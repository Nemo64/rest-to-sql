# Rest to SQL Client

Work in progress! Do not use.

This library makes it simple to build a REST API using a SQL database as the backend.
The aim is to remove as much friction as possible.

There are no Intermediate formats that are mapped between.
This avoids the typical performance issues seen in database applications. 

The configuration maps directly to SQL. There is no new "powerful query language".
Virtual fields, permissions, all done with simple SQL expressions.

## Key features

- It can be used standalone or integrated in any framework as it is a [PSR-15 Server Request Handler](https://www.php-fig.org/psr/psr-15/).  
- minimal configuration with just that one goal: production ready api database
- powerful sql based permissions by default, not just role based access (not yet implemented)
- quick owning relations with permission inheritance (user has email addresses, chat has messages, basket has items etc.)
  See: [There are only 2 types of relations.](https://medium.marco.zone/mastering-doctrine-orm-relations-571060c5b40e)
- fast relational selects using sub queries with `JSON_AGG` / `JSON_ARRAY_AGG` / `JSON_GROUP_ARRAY`.
- transactional updates without complicated query planner while still supporting auto_increment. 
- automatic openapi schema generation from your configuration. Useful for tools like:
  - [Swagger](https://petstore.swagger.io/) (included in test server `bin/test-server` or generatable with `bin/swagger-dump`) 
  - [openapi-typescript](https://github.com/drwpow/openapi-typescript) - for when you actually want to use it
  - and [a lot more](https://openapi.tools/)

## Quick and dirty usage

There are some scripts to get started quickly without integration.

Then run the following commands:
```bash
bin/database-schema-update # creates the database schema (sqlite in rest-to-sql.db by default)
bin/test-server # runs php's built in web server with a working swagger ui
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

## Standalone usage

TODO

```bash
composer require nemo64/rest-to-sql
```

## Model types

### Table

The most basic component. In OpenAPI it would be a `type: array`.


```yaml
inventory:
  type: table
  fields: 
    name: { type: string }
```

## Field types

| `type:`     | DBAL type                                                           | OpenApi Type                                            | special options            |
|-------------|---------------------------------------------------------------------|---------------------------------------------------------|----------------------------|
| `boolean`   | BooleanType                                                         | `"boolean"`                                             |                            |
| `integer`   | SmallIntType, IntegerType, BigIntType (based on minimum/maximum)    | `"integer"` with `format: "int32"` or `format: "int64"` | minimum, maximum           |
| `string`    | StringType, TextType (based on size and if the field is searchable) | `"string"`                                              | enum, minLength, maxLength |
| `date-time` | DateTimeImmutableType                                               | `"string"` with `format: "date-time"`                   |                            |
| `date`      | DateImmutableType                                                   | `"string"` with `format: "date"`                        |                            |

### common field options

- `name` the field name in the api and the database
- `description` description for OpenAPI (default: repeats the select statement, if any)
- `select` makes this field virtual and allows to define a custom sql select statement
- `nullable` if the field can be null (default: false)
- `default` the default value for the field. (default: null, even if the field is not nullable, which means the field is required)
- `searchable` if true, adds parameters to the model to search for this field based on what it is (default: false)
- `sortable` if true, adds parameters to the model to sort by this field (default: false)
- `indexed` if true, adds an index to the field (default: false, unless searchable or sortable)

## Example configuration

This is how a schema looks

```yaml
# config/models/user.model.yaml
users:
  type: table
  access:
    ROLE_ADMIN: true
    ROLE_USER: this.id = :user_id
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
        email:
          type: string
          unique: true
        verified:
          type: boolean
        primary:
          type: boolean
```

That's all, just a readable json-schema-like configuration.

### Generated Database Schema

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

### SQL query for `/api/users` (on MySQL)

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

### Generate OpenAPI Schema

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