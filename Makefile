# daili-go 构建（前端为 pure static，改 static/ 下文件无需编译，重启即生效）
BINARY := daili-go.exe

.PHONY: build test vet clean

build:
	go build -o $(BINARY) .

test:
	go test ./...

vet:
	go vet ./...

clean:
	rm -f $(BINARY) *.db *.db-wal *.db-shm test_*.db*
