# 🚀 MemoryLane - Démarrage Rapide

## ✅ Installation terminée !

Tous les services Podman sont actifs et configurés.

## 🎯 Prochaine étape : Créer un admin

```bash
./podman.sh tinker
```

Puis dans Tinker :

```php
User::create(['name' => 'Admin', 'email' => 'admin@memorylane.com', 'password' => Hash::make('password')]);
```

## 🌐 URLs importantes

- **Application** : http://localhost:8000
- **Admin Panel** : http://localhost:8000/admin
- **Credentials** : admin@memorylane.com / password

## 🛠️ Commandes essentielles

```bash
./podman.sh status   # État des services
./podman.sh logs     # Voir les logs
./podman.sh dev      # Mode développement
./podman.sh test     # Lancer les tests
./podman.sh stop     # Arrêter tout
./podman.sh start    # Démarrer tout
```

## 📚 Documentation complète

- [INSTALLATION_SUCCESS.md](INSTALLATION_SUCCESS.md) - Guide complet
- [PODMAN_SETUP.md](PODMAN_SETUP.md) - Installation Podman
- [README.md](README.md) - Documentation projet

---

**Tout est prêt ! Créez votre admin et connectez-vous 🎉**
