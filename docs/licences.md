# Licences

Every model, asset, dataset and third-party library chosen for Kalpa Studio (framework defaults aside). Only licences that allow commercial use.

| Name | Kind | Version | Licence | Commercial use | Source | Used by |
| --- | --- | --- | --- | --- | --- | --- |
| swaggest/json-diff | PHP library (JSON Patch, RFC 6902) | 3.12 | MIT | Yes | https://github.com/swaggest/json-diff | API: project memory patches |
| @lucide/vue | JS library (icons; the renamed lucide-vue-next) | 1.54 | ISC | Yes | https://github.com/lucide-icons/lucide | Web: sidebar, dashboard, module icons |
| chrislusf/seaweedfs | Service (S3-compatible object storage, Docker image) | 4.48 | Apache-2.0 | Yes | https://github.com/seaweedfs/seaweedfs | kalpa-seaweedfs: file storage |
| league/flysystem-aws-s3-v3 | PHP library (S3 filesystem adapter) | 3.35 | MIT | Yes | https://github.com/thephpleague/flysystem-aws-s3-v3 | API: s3 disk |
| aws/aws-sdk-php | PHP library (S3 client; brought by the adapter, with aws/aws-crt-php Apache-2.0 and mtdowling/jmespath.php MIT) | 3.400 | Apache-2.0 | Yes | https://github.com/aws/aws-sdk-php | API: multipart uploads, presigned URLs |
| hash-wasm | JS library (SHA-256 in WebAssembly) | 4.12 | MIT | Yes | https://github.com/Daninet/hash-wasm | Web: hashing files in a Web Worker before upload |
| opis/json-schema | PHP library (JSON Schema validation, draft 2020-12; brings opis/string and opis/uri, both Apache-2.0) | 2.6 | Apache-2.0 | Yes | https://github.com/opis/json-schema | API: validating asset specs against packages/schemas |
