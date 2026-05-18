# fargate-cortex-agent

AWS ECS Fargate에 nginx 컨테이너를 배포하는 3-스테이지 GitOps 파이프라인.
인프라(VPC/ALB/ECS/ECR)는 CloudFormation으로, 빌드·배포는 GitHub Actions(OIDC 인증)로 처리합니다.

## 아키텍처

```
                        ┌──────────────────────────────────┐
                        │       GitHub repository           │
                        │                                   │
   push to main ───────▶│  .github/workflows/               │
                        │    01-create-cluster.yml          │
                        │    02-build-push.yml ─┐           │
                        │    03-deploy-service.yml          │
                        └──────────────────────────────────┘
                                                 │
                                workflow_call    │  OIDC AssumeRole
                                                 ▼
                        ┌──────────────────────────────────┐
                        │             AWS                   │
                        │                                   │
                        │   ECR ◀── Build & push image      │
                        │    │                              │
                        │    ▼                              │
                        │   ECS Fargate Service ──▶ ALB ──▶ Internet
                        │   (TaskDef revisions)             │
                        └──────────────────────────────────┘
```

## 디렉터리 구조

```
.
├── app/                           # nginx 컨테이너 소스
│   ├── Dockerfile                 # nginx:1.27-alpine + HEALTHCHECK
│   ├── nginx.conf                 # /  + /health 라우팅
│   ├── index.html
│   └── .dockerignore
├── infrastructure/
│   ├── cluster.yaml               # Stage 1: VPC + ECS + ECR + ALB + IAM + Logs
│   └── service.yaml               # Stage 3: TaskDefinition + Service (→ TargetGroup)
└── .github/workflows/
    ├── 01-create-cluster.yml      # CloudFormation ChangeSet 기반 클러스터 배포
    ├── 02-build-push.yml          # Docker Buildx → ECR push → Stage 3 호출
    └── 03-deploy-service.yml      # 서비스 배포 + ALB healthy 대기 + 스모크 테스트
```

## 파이프라인 흐름

| 스테이지 | 트리거 | 산출물 |
|---|---|---|
| **Stage 1** | `infrastructure/cluster.yaml` 변경 push, 또는 수동 | `fargate-nginx-cluster` CFN 스택 (VPC, ECS Cluster, ECR, ALB, TargetGroup, IAM Role, LogGroup) |
| **Stage 2** | `app/**` 변경 push, 또는 수동 | ECR 이미지 `:<short-sha>` + `:latest` |
| **Stage 3** | Stage 2가 `workflow_call`로 자동 호출, 또는 수동 | `fargate-nginx-service` CFN 스택, ALB로 라우팅된 nginx |

Stage 3 단독 호출 시 `image_tag`(기본 `latest`)와 `desired_count`를 입력으로 받습니다.

## 최초 설정 (한 번만)

### 1. AWS OIDC Provider 등록

```bash
aws iam create-open-id-connect-provider \
  --url https://token.actions.githubusercontent.com \
  --client-id-list sts.amazonaws.com \
  --thumbprint-list 6938fd4d98bab03faadb97b34396831e3780aea1
```

### 2. 배포용 IAM Role 생성

GitHub Actions가 Assume할 Role을 만들고, 다음 권한을 부여합니다.
- 인프라 서비스: `cloudformation:*`, `ec2:*`, `elasticloadbalancing:*`, `ecs:*`, `ecr:*`, `logs:*`
- IAM(Task Role 생성용): `fargate-nginx-task-*` 범위로 제한

Trust policy `sub` 조건:
```
repo:<OWNER>/<REPO>:*
```

### 3. GitHub Secret 등록

```bash
gh secret set AWS_DEPLOY_ROLE_ARN \
  --repo <OWNER>/<REPO> \
  --body "arn:aws:iam::<ACCOUNT_ID>:role/<ROLE_NAME>"
```

### 4. (선택) Environment 보호 게이트

`Settings → Environments → New environment → production` 으로 승인자/브랜치 제한을 걸 수 있습니다. Stage 1과 Stage 3이 이 environment를 참조합니다.

## 배포 실행

### 첫 배포 순서

```bash
# 1. 인프라
gh workflow run "Stage 1 - Create ECS Cluster" --ref main

# 2. 이미지 빌드 → 자동으로 Stage 3까지 진행
gh workflow run "Stage 2 - Build & Push Image" --ref main
```

### 일상 배포

`app/` 아래 코드를 수정해 `main`에 push하면 Stage 2 → Stage 3이 자동 실행됩니다.

### 수동 재배포 / 롤백

특정 이미지 태그로 되돌리기:
```bash
gh workflow run "Stage 3 - Deploy ECS Service" --ref main \
  -f image_tag=<short-sha>
```

원하는 태스크 수로 스케일:
```bash
gh workflow run "Stage 3 - Deploy ECS Service" --ref main \
  -f image_tag=latest -f desired_count=3
```

## 운영

### 엔드포인트 확인
```bash
aws cloudformation describe-stacks --stack-name fargate-nginx-cluster \
  --query "Stacks[0].Outputs[?OutputKey=='LoadBalancerDnsName'].OutputValue" --output text
```

### 실시간 로그
```bash
aws logs tail /ecs/fargate-nginx --follow
```

### 서비스 상태
```bash
aws ecs describe-services --cluster fargate-nginx-cluster --services fargate-nginx-svc \
  --query 'services[0].{Status:status,Desired:desiredCount,Running:runningCount,Deployments:deployments[].rolloutState}' \
  --output table
```

### 안전장치
- Service에 `DeploymentCircuitBreaker(Rollback=true)` 활성 → 새 태스크가 안정화되지 않으면 자동 롤백
- `MinimumHealthyPercent=100`, `MaximumPercent=200` → 무중단 롤링 업데이트
- ALB Target Group: `/health` 200 응답을 헬스체크로 사용

## 정리 (스택 삭제)

순서 중요. 서비스 스택을 먼저, 그 다음 클러스터 스택.

```bash
aws cloudformation delete-stack --stack-name fargate-nginx-service
aws cloudformation wait stack-delete-complete --stack-name fargate-nginx-service

aws cloudformation delete-stack --stack-name fargate-nginx-cluster
aws cloudformation wait stack-delete-complete --stack-name fargate-nginx-cluster
```

> ECR 리포지토리에 이미지가 남아있으면 클러스터 스택 삭제가 실패합니다.
> `aws ecr batch-delete-image` 또는 콘솔에서 이미지를 먼저 비우세요.

## 비용 안내

기본 구성(`ap-northeast-2`, Fargate 256 CPU / 512 MB, ALB 1대) 기준:
- **ALB**: 시간당 약 $0.0225 + LCU
- **Fargate Task**: 시간당 약 $0.012 (256/512)
- **NAT Gateway**: 사용 안 함 (Public subnet + AssignPublicIp)
- **ECR**: 스토리지 GB당 월 $0.10

테스트가 끝나면 위 "정리" 절차로 모두 삭제하면 추가 과금이 멈춥니다.

## 워크플로 입력 파라미터

세 워크플로 모두 `workflow_dispatch` 입력을 받습니다.

| 입력 | Stage 1 | Stage 2 | Stage 3 | 기본값 |
|---|---|---|---|---|
| `project_name` | ✓ | ✓ | ✓ | `fargate-nginx` |
| `aws_region` | ✓ | ✓ | ✓ | `ap-northeast-2` |
| `auto_deploy` | | ✓ | | `true` |
| `image_tag` | | | ✓ | `latest` |
| `desired_count` | | | ✓ | `1` |

`project_name`을 변경하면 다른 환경(stage/prod 등)으로 분리 배포할 수 있습니다 — 단, 한 워크플로 실행 내에서 모든 스테이지에 동일한 값을 넘기세요.
