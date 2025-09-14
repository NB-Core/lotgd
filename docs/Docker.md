# Docker Environment

### docker-compose.yml

```yaml

volumes:
  db_data:

networks:
  lotgd-network:
```

### .env File

Copy `.env.example` to `.env` in the root directory and adjust values as needed. The default file contains:

```env
MYSQL_DATABASE=lotgd
MYSQL_USER=lotgduser
MYSQL_PASSWORD=lotgdpass
MYSQL_ROOT_PASSWORD=rootpass
```

> **Note:** Change these default passwords for production use.

### .htaccess

The root `.htaccess` file configures custom error pages, disables directory listings, protects sensitive files, and blocks the `install/` folder when `index.php` is removed. Nginx equivalents are provided as comments in that file.

---

## Notes

### Port Configuration

- The container exposes **port 80**. Ensure this port is available on your host machine.
- For production use, you should employ a reverse proxy (e.g., Nginx) and configure SSL/TLS.

### SSL/TLS

- The current configuration **does not support SSL/TLS**.
- **SSL/TLS must be configured separately**, especially for production environments.
- Consider using Let's Encrypt or another certificate provider.

### Persistent Volumes

- The `db_data` volume ensures that database data is stored persistently.
- **Adjusting Volumes:**
  - Modify the volumes in `docker-compose.yml` as needed.
  - Consider using named volumes or mounting a host directory for backups.

### Security

- **Change Passwords:** Update the default passwords in the `.env` file.
- **Access Rights:** Ensure that sensitive files are not publicly accessible.
- **Updates:** Keep your Docker images and dependencies up to date.
- **Firewall:** Configure your firewall appropriately to prevent unauthorized access.

---

## Useful Commands

- **Stop Containers:**

  ```bash
  docker-compose down
  ```

- **Restart Containers:**

  ```bash
  docker-compose restart
  ```

- **View Logs:**

  ```bash
  docker-compose logs -f
  ```

- **Access the Web Container:**

  ```bash
  docker-compose exec web bash
  ```

- **Access the Database Container:**

  ```bash
  docker-compose exec db bash
  ```

---

## Troubleshooting

- **Web Container Fails to Start:**
  - Check logs with `docker-compose logs web`.
  - Ensure the base image is correct (`php:8.1-apache`).

- **Database Connection Fails:**
  - Verify that the environment variables in the `.env` file are correct.
  - Check the database settings in your application.

- **Code Changes Not Reflected:**
  - Ensure you have rebuilt the container after making changes to the Dockerfile.
  - Clear your application's cache or your browser's cache if necessary.
- **Installer Log Location:**
  - The installer writes to `install/errors/install.log`. If you see a warning
    that the log could not be written, ensure this path is writable.

- **Ubuntu Private /tmp notice:**
  - On some Ubuntu systems `systemd` isolates the `/tmp` directory for the
    web server. If PHP has `display_errors` enabled, warnings about writing
    temporary files may be output directly to the browser and interrupt the
    installer. Set `display_errors = Off` in your PHP configuration (typically
    located at `/etc/php/<version>/apache2/php.ini`) when this happens. After
    making this change, restart your web server (e.g., `sudo systemctl restart apache2`)
    so the installer and game can run correctly.

### Where to find installer logs

Installer errors are saved to `install/errors/install.log`. Check this file if
the installer fails or reports problems.

## Contributing & Support

Found a bug or have a feature request? Open an issue on GitHub.
Pull requests are welcome for improvements or fixes.
Run the unit tests with `composer test` and check modified PHP files using
`php -l` before submitting PRs. Check coding style with `composer lint` and
apply automatic fixes using `composer lint:fix`.

## License

This project is licensed under the [Creative Commons License](LICENSE).

---

**Note:** This Docker environment is intended for development and testing purposes. Additional configurations and security measures are required for production use.

# Enjoy running LOTGD with Docker!
