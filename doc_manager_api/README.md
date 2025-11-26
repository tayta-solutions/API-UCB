# API de Gestão de Documentos (PHP + MySQL)

Esta é uma API simples para:
- Cadastro e login de usuários
- Gestão de pastas
- Upload e download de documentos (armazenados no banco)

## Estrutura

```text
doc-manager-api/
├── index.php            # API principal
├── db.php               # Conexão com banco
├── config.example.php   # Exemplo de configuração
├── openapi.yaml         # Especificação OpenAPI/Swagger
├── .gitignore
├── database/
│   └── schema.sql       # Script do banco MySQL
└── docs/
    └── index.php        # Swagger UI (/docs)
```

## Como usar

1. Crie o banco de dados:

   ```sql
   SOURCE database/schema.sql;
   ```

2. Copie o arquivo de configuração:

   ```bash
   cp config.example.php config.php
   ```

   Edite `config.php` com as credenciais reais do banco.

3. Publique o projeto em um servidor PHP (Apache/Nginx).

4. Endpoints principais:

   - `POST /register` – cadastro de usuário  
   - `POST /login` – login simples  
   - `POST /folders` – criar pasta  
   - `GET /folders` – listar pastas  
   - `DELETE /folders/{id}` – deletar pasta  
   - `GET /folders/{id}/documents` – listar documentos da pasta  
   - `POST /documents` – upload de documento (multipart/form-data)  
   - `GET /documents/{id}` – download de documento  

5. Documentação Swagger

   Acesse:

   ```
   /docs
   ```

   O Swagger UI será carregado usando `openapi.yaml`.
