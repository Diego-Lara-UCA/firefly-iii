# Guía de Instalación: Firefly III

Esta guía proporciona instrucciones detalladas para la instalación y configuración de Firefly III en un entorno de desarrollo local. Se enfoca en el uso de Docker para la gestión de la base de datos PostgreSQL, simplificando la configuración inicial.

## 1. Prerrequisitos

Antes de comenzar, asegúrese de que su sistema cumple con los siguientes requisitos:

-   **PHP**: Versión 8.4.0 o superior. ([php.net](https://www.php.net/downloads.php))
-   **Composer**: Gestor de dependencias para PHP. ([getcomposer.org](https://getcomposer.org/download/))
-   **Docker & Docker Compose**: Para la gestión de contenedores. ([docs.docker.com](https://docs.docker.com/get-docker/))
-   **Node.js**: Entorno de ejecución para JavaScript. ([nodejs.org](https://nodejs.org/))
-   **npm**: Gestor de paquetes para Node.js (usualmente incluido con Node.js). ([docs.npmjs.com](https://docs.npmjs.com/downloading-and-installing-node-js-and-npm))

## 2. Clonar el Repositorio

Obtenga la última versión del código fuente de Firefly III:

```bash
git clone https://github.com/Diego-Lara-UCA/firefly-iii
cd firefly-iii
```

## 3. Configuración del Entorno

La configuración de la aplicación se gestiona mediante archivos de entorno.

### 3.1 Archivo de Entorno Principal (`.env`)

Este archivo define parámetros cruciales de la instancia, como la conexión a la base de datos y la URL de la aplicación.

1.  **Creación del archivo `.env`**:
    El repositorio incluye `.env.example` como plantilla. Cópielo para crear su archivo de configuración personal:

    ```bash
    cp .env.example .env
    ```

    Si `.env.example` no está disponible, puede obtener la plantilla desde el [repositorio oficial de Firefly III](https://raw.githubusercontent.com/firefly-iii/firefly-iii/main/.env.example) y crear el archivo `.env` manualmente.

    **Nota**: La plantilla está dirigida para MySQL, pero en esta guía se utilizará PostgreSQL. Asegúrese de ajustar las variables de conexión a la base de datos según sea necesario.

2.  **Personalización de Variables**:
    Abra `.env` y ajuste las variables según su entorno. Preste especial atención a las siguientes variables de conexión a la base de datos para PostgreSQL:

    ```env
    DB_CONNECTION=pgsql
    DB_HOST=127.0.0.1
    DB_PORT=5432
    DB_DATABASE=firefly
    DB_USERNAME=firefly
    DB_PASSWORD=secret_firefly_password 
    ```

    Asegúrese de que estos valores sean consistentes con la configuración de su servidor de base de datos. Modifique `DB_PASSWORD` para establecer una contraseña segura.

    **Nota importante sobre `DB_HOST`**:
    *   Dado que esta guía ejecuta la aplicación PHP directamente en su máquina host (usando `php artisan serve`) y la base de datos en un contenedor Docker, `DB_HOST` **debe ser `127.0.0.1` o `localhost`**. Esto se debe a que la aplicación se conecta al puerto de la base de datos que ha sido mapeado desde el contenedor a su máquina host.
    *   Si en el futuro decidiera ejecutar la aplicación Firefly III también dentro de un contenedor Docker (en la misma red Docker que el contenedor de la base de datos), entonces `DB_HOST` debería cambiarse al nombre del servicio de la base de datos definido en `docker-compose.yml` (que en esta guía es `db`).

### 3.2 Archivo de Entorno para la Base de Datos (`.db.env`)

Este archivo es utilizado por Docker Compose para configurar las credenciales y parámetros del contenedor de la base de datos PostgreSQL.

1.  **Creación del archivo `.db.env`**:
    Cree este archivo en la raíz del proyecto.

2.  **Configuración para PostgreSQL**:
    Añada el siguiente contenido. Estos valores deben coincidir con los especificados en el archivo `.env` para `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`.

    ```env
    POSTGRES_USER=firefly
    POSTGRES_PASSWORD=secret_firefly_password
    POSTGRES_DATABASE=firefly
    ```

### 3.3 Archivo de Orquestación de Contenedores (`docker-compose.yml`)

Docker Compose utiliza este archivo para definir y gestionar los servicios de la aplicación, en este caso, el contenedor de la base de datos PostgreSQL.

1.  **Creación del archivo `docker-compose.yml`**:
    Cree este archivo en la raíz del proyecto.

2.  **Definición del Servicio de Base de Datos**:
    Copie el siguiente contenido:

    ```yaml
    version: "3.8"

    services:
        db:
            image: postgres:15
            container_name: firefly_iii_postgres_db_local
            restart: unless-stopped
            env_file:
                - .db.env
            ports:
                - "5432:5432"
            volumes:
                - pgdata_local:/var/lib/postgresql/data
            networks:
                - firefly_local_net

    volumes:
        pgdata_local:

    networks:
        firefly_local_net:
            driver: bridge
    ```

    **Resumen de la configuración**:

    -   Utiliza la imagen `postgres:15`.
    -   Reinicia el contenedor automáticamente (`unless-stopped`).
    -   Carga la configuración de la base de datos desde `.db.env`.
    -   Expone el puerto `5432` del contenedor al `5432` del host.
    -   Utiliza un volumen llamado `pgdata_local` para la persistencia de los datos.
    -   Conecta el servicio a una red `firefly_local_net` para comunicación aislada.

## 4. Iniciar el Contenedor de la Base de Datos

Con `docker-compose.yml` configurado, inicie el servicio de la base de datos en segundo plano (`-d`):

```bash
docker-compose up -d --build
```

La opción `--build` asegura que la imagen se construya si es necesario (aunque para imágenes de Docker Hub como `postgres`, usualmente solo se descarga).

## 5. Verificar el Estado del Contenedor

Asegúrese de que el contenedor de la base de datos se esté ejecutando correctamente:

```bash
docker ps
```

Debería ver un contenedor con el nombre `firefly_iii_postgres_db_local` en la lista.

## 6. Compilar Activos del Frontend

Firefly III requiere la compilación de sus activos de frontend (CSS, JavaScript).

1.  **Instalar Dependencias de Node.js**:
    Este comando lee `package.json` e instala las dependencias necesarias.

    ```bash
    npm install
    ```

2.  **Compilar Activos (v1 y v2)**:
    Estos comandos compilan los activos para las diferentes partes de la interfaz de Firefly III.
    ```bash
    npm run prod --workspace=v1 
    npm run build --workspace=v2 
    ```
    _Nota: Los nombres de los workspaces (`v1`, `v2`) o los scripts exactos (`prod`, `build`) pueden variar según la versión de Firefly III. Consulte la [documentación oficial](https://docs.firefly-iii.org/references/faq/development/) para más información._

## 7. Instalar Dependencias de PHP

Instale las librerías PHP requeridas por el backend utilizando Composer:

```bash
composer update --no-dev --no-scripts --no-plugins
```

Las opciones `--no-dev --no-scripts --no-plugins` optimizan la instalación para un entorno de producción o similar, excluyendo herramientas de desarrollo y la ejecución automática de scripts.

## 8. Configuración Final y Ejecución de la Aplicación

Los siguientes pasos prepararán la base de datos y ejecutarán la aplicación.

1.  **Ejecutar Migraciones de la Base de Datos**:
    Este comando Artisan crea la estructura de tablas necesaria en la base de datos.

    ```bash
    php artisan migrate
    ```

    Es posible que se solicite confirmación, especialmente si el sistema detecta un entorno de producción.

2.  **Iniciar el Servidor de Desarrollo**:
    Laravel (el framework sobre el que se construye Firefly III) incluye un servidor de desarrollo.

    ```bash
    php artisan serve
    ```

    Una vez iniciado, la aplicación estará disponible, por defecto, en `http://127.0.0.1:8000` o `http://localhost:8000`.

    _Nota: Para entornos de producción, se recomienda utilizar un servidor web robusto como Nginx o Apache en lugar de `php artisan serve`._

