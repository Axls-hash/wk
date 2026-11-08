# wk_dl —— 多阶段构建：编译 Go 后端 + 拷贝 static 前端
# 生产建议配 MySQL；演示可设 ALLOW_SQLITE=1

FROM golang:1.25-bookworm AS builder
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux GOARCH=amd64 \
    go build -trimpath -ldflags="-s -w" -o /out/daili-go .

FROM debian:bookworm-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates tzdata curl \
 && rm -rf /var/lib/apt/lists/* \
 && useradd -r -u 10001 -d /app appuser
WORKDIR /app
COPY --from=builder /out/daili-go /app/daili-go
COPY static /app/static
# .env 不打进镜像：运行时用环境变量 / 挂载
RUN mkdir -p /app/logs /app/backups /app/data \
 && chown -R appuser:appuser /app
USER appuser
ENV ADDR=:8000 \
    TZ=Asia/Shanghai
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS http://127.0.0.1:8000/api/health || exit 1
CMD ["/app/daili-go"]
