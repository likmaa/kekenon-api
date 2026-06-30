# Sauvegardes MySQL (INFRA-04)

## Script

`docker/backup-db.sh` produit un fichier `backup_<db>_<timestamp>.sql.gz` dans `docker/backups/` (monté sur l’hôte via `docker-compose.prod.yml`).

Prérequis sur la machine qui exécute le script :

- Docker CLI
- Conteneur MySQL en cours d’exécution
- Variable `CONTAINER_NAME` dans le script alignée sur `docker ps` (par défaut `backend-mysql-1`)

## Installation cron

1. Rendre le script exécutable : `chmod +x docker/backup-db.sh`
2. Tester une fois : `cd /chemin/vers/backend && ./docker/backup-db.sh`
3. Ajouter une ligne crontab (utilisateur qui a accès à Docker) :

```cron
0 3 * * * cd /chemin/vers/backend && /usr/bin/env bash ./docker/backup-db.sh >> /var/log/kekenon-backup.log 2>&1
```

## S3 (optionnel)

1. Installer AWS CLI v2 sur le serveur et configurer un profil IAM avec droit `s3:PutObject` sur le bucket cible.
2. Définir `BACKUP_S3_BUCKET=nom-du-bucket` dans l’environnement du cron ou dans `.env`.
3. Décommenter le bloc S3 en tête de `backup-db.sh` (section indiquée dans les commentaires du script).

## Rétention

Les fichiers plus vieux que `RETENTION_DAYS` (30 par défaut) sont supprimés automatiquement.
