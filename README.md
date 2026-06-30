<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Endpoints de l’API

Ci‑dessous, une référence concise des endpoints disponibles. Tous les chemins sont préfixés par `/api`.

### Auth
- POST `/auth/request-otp`
  - Limitation: `otp`
  - Corps: `{ phone?: string, email?: string }`
  - Sert à demander un mot de passe à usage unique (OTP).

- POST `/auth/verify-otp`
  - Limitation: `otp`
  - Corps: `{ phone?: string, email?: string, code: string }`
  - Retourne un token Sanctum en cas de succès.

- POST `/auth/logout`
  - Auth: `auth:sanctum`
  - Invalide le token courant.

- GET `/auth/me`
  - Auth: `auth:sanctum`
  - Retourne le profil de l’utilisateur authentifié.

### Admin
- POST `/admin/login`
  - Corps: `{ login: string, password: string }` (e‑mail/téléphone pris en charge)
  - Retourne un token Sanctum administrateur.

- Les routes ci‑dessous requièrent `auth:sanctum` + `role:admin,developer`:
  - POST `/admin/logout`
  - GET `/admin/me`
  - GET `/admin/ping`
  - GET `/admin/drivers/pending` — lister les chauffeurs en attente
  - PATCH `/admin/drivers/{id}/status` — mettre à jour le statut d’un chauffeur
  - GET `/admin/users` — lister les utilisateurs avec filtres
  - GET `/admin/users/{id}` — afficher un utilisateur
  - PATCH `/admin/users/{id}` — mettre à jour un utilisateur
  - DELETE `/admin/users/{id}` — supprimer un utilisateur
  - GET `/admin/pricing` — lire la configuration tarifaire
  - PUT `/admin/pricing` — modifier la configuration tarifaire
  - GET `/admin/rides` — lister les courses
  - GET `/admin/finance/summary` — résumé financier
  - GET `/admin/finance/transactions` — transactions financières
  - POST `/admin/notifications/broadcast` — envoyer une notification
  - GET `/admin/stats/drivers/daily`
    - Query: `driver_id` (obligatoire), `from`, `to`, `tz`
    - Statistiques par jour pour un chauffeur (agrégation UTC)
  - GET `/admin/stats/drivers/daily/global`
    - Query: `from`, `to`, `tz`
    - Statistiques globales par jour (tous chauffeurs)
  - GET `/admin/stats/drivers/daily/top`
    - Query: `from`, `to`, `limit`, `tz`
    - Meilleurs chauffeurs par jour

### Driver
- Toutes les routes requièrent `auth:sanctum` + `role:driver` + `driver.approved`:
  - GET `/driver/ping`
  - POST `/driver/trips/{id}/accept`
  - POST `/driver/trips/{id}/start`
  - POST `/driver/trips/{id}/complete` — terminer une course (commission appliquée)
  - POST `/driver/trips/{id}/cancel` — annuler avec raison optionnelle

### Passenger
- Toutes les routes requièrent `auth:sanctum` + `role:passenger`:
  - GET `/passenger/ping`
  - POST `/passenger/trips/{id}/cancel` — annuler une course (requested/accepted)
  - POST `/passenger/ratings` — noter un chauffeur (1 à 5 étoiles)
    - Corps: `{ ride_id: number, stars: 1..5, comment?: string }`
    - Contraintes: une seule note par course et par passager; course terminée requise
    - Effet: additionne les étoiles du chauffeur; à chaque palier de 100 points cumulés, un bonus de `5000 FCFA` est enregistré

### Trips (partagé)
- POST `/trips/estimate`
  - Auth: `auth:sanctum`
  - Corps: `{ pickup:{lat,lng}, dropoff:{lat,lng}, distance_m, duration_s }`
  - Retourne `{ price, currency, eta_s, distance_m }` à partir des métriques fournies.

- POST `/trips/create`
  - Auth: `auth:sanctum`
  - Corps: `{ pickup:{lat,lng,label?}, dropoff:{lat,lng,label?}, distance_m, duration_s, price }`
  - Persiste un enregistrement `rides` avec le statut `requested`.

### Géocodage (public, limité)
- GET `/geocoding/search`
  - Limitation: `120/min`
  - Query: `query` (obligatoire), `language` (défaut `fr`), `limit` (défaut `8`)
  - Retourne `{ results: [{ place_id, display_name, lat, lon }, ...] }`

- GET `/geocoding/reverse`
  - Limitation: `120/min`
  - Query: `lat` (obligatoire), `lon` (obligatoire), `language` (défaut `fr`)
  - Retourne `{ address: string|null }`

### Itinéraires (public, limité)
- POST `/routing/estimate`
  - Limitation: `120/min`
  - Corps: `{ pickup:{lat,lng}, dropoff:{lat,lng} }`
  - Calcule distance/durée via Mapbox Directions et retourne `{ price, currency, eta_s, distance_m, source }`

### Notes
- Fuseau horaire: les statistiques chauffeur sont agrégées en UTC par défaut; `tz` peut être fourni.
- Des limites de débit s’appliquent aux endpoints publics de géocodage/itinéraires.
- Les secrets (token Mapbox) sont uniquement côté serveur; le frontend ne les expose jamais.
- Récompenses chauffeur: un enregistrement est créé dans `driver_rewards` à chaque palier atteint (100, 200, ... points cumulés), montant `5000 FCFA` par palier.
