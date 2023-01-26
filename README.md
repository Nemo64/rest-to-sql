# Rest to SQL Client

Work in progress! Do not use.

This library makes it simple to build a REST API using a SQL database as the backend.
The aim is to remove as much friction as possible.
There are no Intermediate formats that are mapped between to avoid typical performance issues. 
And the configuration directly maps to SQL without any meta language.

## Key features

- simple schema configuration with common patterns handled by default
- fast relational selects using sub queries with `JSON_AGG` / `JSON_ARRAY_AGG` / `JSON_GROUP_ARRAY`
- automatic openapi schema generation from your configuration

## Test Usage

There are some scripts to get started quickly without integration.

Then run the following commands:
```bash
bin/database-schema-update # creates the database schema (sqlite in rest-to-sql.db by default)
bin/debug-server # runs php's built in web server with a working swagger ui
```

If you want to use a different database or a different schema, you can define these environment variables:
```bash
# define what database to use
DATABASE_URL=sqlite:///test.db
DATABASE_URL=mysql://root:password@localhost:3306/test
DATABASE_URL=postgre://root:password@localhost:5432/test

# define where to find the config yaml files
REST_TO_SQL_MODEL_PATH='/path/to/*.model.yaml'
```

## Example

This is how a schema looks

```yaml
# config/models/user.model.yaml
users:
  type: table
  access:
    ROLE_ADMIN: true
    ROLE_USER: { select: this.id = :user_id }
  fields:
    status:
      type: string
      enum: [ invited, enabled, disabled ]
      default: invited
      searchable: true
    enabled:
      type: boolean
      select: this.status = 'enabled'
    username:
      type: string
      select: (SELECT email FROM users_emails WHERE users_emails.users = this.id AND users_emails.primary = 1)
      searchable: true
    emails:
      type: table
      fields:
        email: { type: string, unique: true }
        verified: { type: boolean }
        primary: { type: boolean }
```

It will create this database schema

```sql
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  status VARCHAR(50) DEFAULT 'invited' NOT NULL
) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB;

CREATE TABLE users_emails (
  parent INT UNSIGNED NOT NULL,
  id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  email VARCHAR(50) NOT NULL,
  verified TINYINT(1) NOT NULL,
  `primary` TINYINT(1) NOT NULL,
  INDEX IDX_436666C23D8E604F (parent)
) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB;

ALTER TABLE
  users_emails
ADD
  CONSTRAINT FK_436666C23D8E604F FOREIGN KEY (parent) REFERENCES users (id) ON DELETE CASCADE;
```

This is how request on `/api/users` will be executed (on MySQL)

```sql
SELECT
  JSON_OBJECT(
    'id', users.id,
    'status', users.status,
    'enabled', users.status = 'enabled',
    'username',
    (
      SELECT
        email
      FROM
        users_emails
      WHERE
        users_emails.users = users.id
        AND users_emails.primary = 1
    ),
    'emails',
    COALESCE(
      (
        SELECT
          JSON_ARRAYAGG(
            JSON_OBJECT(
              'id', users_emails.id,
              'email', users_emails.email,
              'verified', users_emails.verified,
              'primary', users_emails.primary
            )
          )
        FROM
          users_emails
        WHERE
          users_emails.parent = users.id
      ),
      JSON_ARRAY()
    )
  )
FROM
  users
```

And this is what the open api schema looks like:

```yaml
openapi: 3.0.0
info:
  title: 'RestToSql API'
  version: 1.0.0
paths:
  /api/users:
    get:
      tags:
        - users
      parameters:
        - { name: id, in: query, required: false, schema: { type: integer } }
        - { name: status, in: query, required: false, schema: { type: string, enum: [invited, enabled, disabled], default: invited } }
        - { name: username, in: query, required: false, schema: { type: string, description: '(SELECT email FROM users_emails WHERE users_emails.users = this.id AND users_emails.primary = 1)', maxLength: 50 } }
      responses:
        200: { content: { application/json: { schema: { type: array, items: { $ref: '#/components/schemas/users' } } } } }
        403: { content: { application/json: { schema: { $ref: '#/components/schemas/error' } } } }
    post:
      tags:
        - users
      requestBody:
        content: { application/json: { schema: { $ref: '#/components/schemas/users' } } }
      responses:
        201: { content: { application/json: { schema: { $ref: '#/components/schemas/users' } } } }
        400: { content: { application/json: { schema: { $ref: '#/components/schemas/error' } } } }
        403: { content: { application/json: { schema: { $ref: '#/components/schemas/error' } } } }
        422: { content: { application/json: { schema: { $ref: '#/components/schemas/error' } } } }
  '/api/users/{id}':
    get:
      tags:
        - users
      parameters:
        - { name: id, in: path, required: true, schema: { type: integer } }
      responses:
        200: { content: { application/json: { schema: { $ref: '#/components/schemas/users' } } } }
        403: { content: { application/json: { schema: { $ref: '#/components/schemas/error' } } } }
    patch:
      tags:
        - users
      parameters:
        - { name: id, in: path, required: true, schema: { type: integer } }
      requestBody:
        content: { application/json: { schema: { $ref: '#/components/schemas/users' } } }
      responses:
        200: { content: { application/json: { schema: { $ref: '#/components/schemas/users' } } } }
        400: { content: { application/json: { schema: { $ref: '#/components/schemas/error' } } } }
        403: { content: { application/json: { schema: { $ref: '#/components/schemas/error' } } } }
        422: { content: { application/json: { schema: { $ref: '#/components/schemas/error' } } } }
    delete:
      tags:
        - users
      parameters:
        - { name: id, in: path, required: true, schema: { type: integer } }
      requestBody:
        content: { application/json: { schema: { $ref: '#/components/schemas/users' } } }
      responses:
        204: {  }
        400: { content: { application/json: { schema: { $ref: '#/components/schemas/error' } } } }
        403: { content: { application/json: { schema: { $ref: '#/components/schemas/error' } } } }
        422: { content: { application/json: { schema: { $ref: '#/components/schemas/error' } } } }
components:
  schemas:
    error:
      type: object
      properties:
        message: { description: 'The error message.', type: string }
    users:
      type: object
      properties:
        id: { type: integer, readOnly: true }
        status: { type: string, enum: [invited, enabled, disabled], default: invited }
        enabled: { type: boolean, readOnly: true, description: "this.status = 'enabled'" }
        username: { type: string, readOnly: true, description: '(SELECT email FROM users_emails WHERE users_emails.users = this.id AND users_emails.primary = 1)', maxLength: 50 }
        emails: { type: array, items: { $ref: '#/components/schemas/users_emails' } }
    users_emails:
      type: object
      properties:
        id: { type: integer, readOnly: true }
        email: { type: string, maxLength: 50 }
        verified: { type: boolean }
        primary: { type: boolean }
```