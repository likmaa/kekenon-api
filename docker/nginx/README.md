# Nginx — TLS (INFRA-03)

## Comportement par défaut (image Docker)

- **Port 80** : application Laravel (HTTP), en-têtes de sécurité, emplacement `/.well-known/acme-challenge/` pour renouvellement **HTTP-01** (Let's Encrypt) si vous servez les fichiers depuis `public/.well-known/`.
- **Port 443** : même application avec **TLS** ; l’image génère des certificats **auto-signés** au build (`/etc/nginx/ssl/fullchain.pem`, `privkey.pem`). Les navigateurs afficheront un avertissement tant que vous n’avez pas remplacé ces fichiers par des certificats publics.

## Remplacer par un certificat réel (recommandé en production)

1. Obtenir `fullchain.pem` et `privkey.pem` (ex. **Certbot**, **acme.sh**, ou certificat fourni par votre hébergeur / Cloudflare).
2. Les placer dans `backend/docker/nginx/ssl/` sur la machine qui exécute Compose.
3. Dans `docker-compose.prod.yml`, **décommenter** la ligne de volume :

   `- ./docker/nginx/ssl:/etc/nginx/ssl:ro`

4. Reconstruire / redémarrer le conteneur `app`.

Le montage **remplace** les certificats embarqués dans l’image pour ce répertoire.

## Redirection HTTP → HTTPS

Dans `default.conf`, dans le bloc `listen 80`, sous `location /`, décommenter :

`return 301 https://$host$request_uri;`

et commentez ou supprimez la ligne `try_files` du même bloc **uniquement** lorsque le **443** sert des certificats reconnus par les clients (sinon les utilisateurs en HTTP seront renvoyés vers un HTTPS encore en auto-signé).

## Vérification rapide

```bash
curl -k -I https://localhost:443/up
curl -I http://localhost:80/up
```

(Depuis la machine hôte, adapter le host/port selon le mapping Compose.)
