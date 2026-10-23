<template>
  <Head :title="$t('Installation')" />
  <div class="min-h-screen bg-[#faf7f1] text-[#1c1917]">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-12 grid lg:grid-cols-[17rem_minmax(0,1fr)] gap-8">
      <!-- Étapes -->
      <aside class="lg:sticky lg:top-8 self-start">
        <div class="flex items-center gap-3 mb-6">
          <img src="/icons/icon-192.png" alt="" class="w-11 h-11 rounded-xl" />
          <div class="flex-1">
            <div class="font-display text-2xl font-bold leading-none">MemoryLane</div>
            <div class="text-sm text-stone-500 mt-1">{{ $t('Installation') }}</div>
          </div>
          <LocaleSwitch />
        </div>
        <ol class="hidden lg:flex flex-col gap-1">
          <li v-for="(s, i) in STEPS" :key="s.key">
            <button
              type="button"
              class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-left text-[15px] transition-colors"
              :class="[
                current === s.key ? 'bg-white shadow-sm font-semibold' : 'hover:bg-white/60',
                canGo(s.key) ? '' : 'opacity-40 cursor-not-allowed',
              ]"
              :disabled="!canGo(s.key)"
              @click="go(s.key)"
            >
              <span
                class="w-7 h-7 shrink-0 rounded-full flex items-center justify-center text-xs font-bold"
                :class="isDone(s.key) ? 'bg-emerald-600 text-white' : current === s.key ? 'bg-amber-500 text-white' : 'bg-stone-200 text-stone-600'"
              >
                <svg v-if="isDone(s.key)" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 13l4 4L19 7" /></svg>
                <template v-else>{{ i + 1 }}</template>
              </span>
              {{ s.label }}
            </button>
          </li>
        </ol>
        <div class="lg:hidden">
          <div class="flex justify-between text-sm text-stone-600 mb-2">
            <span class="font-semibold">{{ stepInfo.label }}</span>
            <span>{{ $t('Étape :n sur :total', { n: stepIndex + 1, total: STEPS.length }) }}</span>
          </div>
          <div class="h-1.5 rounded-full bg-stone-200 overflow-hidden">
            <div class="h-full bg-amber-500 transition-all" :style="{ width: ((stepIndex + 1) / STEPS.length) * 100 + '%' }"></div>
          </div>
        </div>
      </aside>

      <main class="min-w-0">
        <!-- ============ Code d'accès ============ -->
        <section v-if="!unlockedNow" class="card">
          <h1 class="title">{{ $t('Bienvenue !') }}</h1>
          <p class="lead">{{ $t('Cet assistant va configurer votre MemoryLane, étape par étape. Chaque choix est expliqué, et la plupart pourront être changés plus tard.') }}</p>
          <form class="mt-6 max-w-sm" @submit.prevent="unlock">
            <label class="label" for="token">{{ $t('Code d\'accès') }}</label>
            <p class="hint">
              <Trans text="Il est affiché dans le terminal où vous avez lancé :cmd. Il prouve que c'est bien vous qui installez."><template #cmd><code>./install.sh</code></template></Trans>
            </p>
            <input id="token" v-model="token" class="input text-2xl tracking-[.3em] font-bold uppercase text-center" maxlength="12" autocomplete="off" autofocus />
            <p v-if="errors.token" class="error">{{ errors.token }}</p>
            <button class="btn-primary mt-4 w-full" :disabled="busy || token.trim().length < 6">{{ $t('Commencer') }}</button>
          </form>
        </section>

        <template v-else>
          <!-- ============ 1. Vérifications ============ -->
          <section v-if="current === 'checks'" class="card">
            <h1 class="title">{{ $t('Avant de commencer') }}</h1>
            <p class="lead">
              <Trans text="MemoryLane rassemble les photos, vidéos et souvenirs de votre famille : albums, arbre généalogique, reconnaissance des visages, carte, frise de vie… sur :yours serveur. Comptez une dizaine de minutes."><template #yours><strong>{{ $t('votre') }}</strong></template></Trans>
            </p>
            <div class="grid sm:grid-cols-2 gap-3 mt-6">
              <div class="tip"><strong>{{ $t('Vous aurez peut-être besoin de :') }}</strong> {{ $t('un nom de domaine (pour y accéder de partout), un compte e-mail d\'envoi (SMTP), un compte de stockage S3. Rien n\'est obligatoire : sans eux, tout fonctionne à la maison.') }}</div>
              <div class="tip"><strong>{{ $t('Rien ne part ailleurs') }}</strong> {{ $t('que là où vous le décidez : pas de publicité, pas de suivi, pas de compte chez nous.') }}</div>
            </div>
            <h2 class="subtitle mt-8">{{ $t('Vérification du serveur') }}</h2>
            <p v-if="!checks" class="hint">{{ $t('Vérification en cours…') }}</p>
            <ul v-else class="mt-3 divide-y divide-stone-100 border border-stone-200 rounded-2xl bg-white">
              <li v-for="c in checks.checks" :key="c.label" class="flex items-start gap-3 px-4 py-3">
                <span class="mt-0.5 w-5 h-5 shrink-0 rounded-full flex items-center justify-center text-white text-xs font-bold" :class="{ 'bg-emerald-600': c.status === 'ok', 'bg-amber-500': c.status === 'warn', 'bg-red-600': c.status === 'error' }">{{ c.status === 'ok' ? '✓' : '!' }}</span>
                <span class="flex-1 min-w-0">
                  <span class="font-medium">{{ c.label }}</span>
                  <span class="text-stone-500"> — {{ c.detail }}</span>
                  <span v-if="c.help && c.status !== 'ok'" class="block text-sm text-stone-500 mt-0.5">{{ c.help }}</span>
                </span>
              </li>
            </ul>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="loadChecks">{{ $t('Revérifier') }}</button>
              <button type="button" class="btn-primary" :disabled="!checks || checks.blocking" @click="go('site')">{{ $t('Continuer') }}</button>
            </div>
            <p v-if="checks?.blocking" class="error">{{ $t('Un point bloquant doit être corrigé avant de continuer (voir l\'aide ci-dessus).') }}</p>
          </section>

          <!-- ============ 2. Votre site ============ -->
          <section v-if="current === 'site'" class="card">
            <h1 class="title">{{ $t('Votre site') }}</h1>
            <p class="lead">{{ $t('Son nom, et comment votre famille y accédera.') }}</p>

            <label class="label mt-6" for="name">{{ $t('Nom du site') }}</label>
            <p class="hint">{{ $t('Affiché en haut des pages et dans les e-mails. Par exemple : « Les souvenirs des Martin ».') }}</p>
            <input id="name" v-model="site.name" class="input" maxlength="60" />
            <p v-if="errors.name" class="error">{{ errors.name }}</p>

            <h2 class="subtitle mt-8">{{ $t('Comment y accédera-t-on ?') }}</h2>
            <div class="grid gap-3 mt-3">
              <label class="choice" :class="{ on: site.access === 'lan' }">
                <input v-model="site.access" type="radio" value="lan" class="sr-only" />
                <span class="choice-title">{{ $t('Seulement depuis la maison') }}</span>
                <span class="choice-text">
                  <Trans text="Le plus simple : à l'adresse :url, sur votre réseau. Mais pas d'accès en vacances, et sans HTTPS, pas de notifications ni d'installation comme application sur les téléphones. Vous pourrez passer à un domaine plus tard."><template #url><code>{{ state.suggestedUrl }}</code></template></Trans>
                </span>
              </label>
              <label class="choice" :class="{ on: site.access === 'domain' }">
                <input v-model="site.access" type="radio" value="domain" class="sr-only" />
                <span class="choice-title">{{ $t('De partout, avec mon nom de domaine') }} <span class="badge">{{ $t('HTTPS automatique') }}</span></span>
                <span class="choice-text">{{ $t('Pour toute la famille, où qu\'elle soit. Le certificat HTTPS est obtenu et renouvelé tout seul (Let\'s Encrypt, gratuit).') }}</span>
              </label>
              <div v-if="site.access === 'domain'" class="pl-1 sm:pl-4">
                <label class="label" for="domain">{{ $t('Nom de domaine') }}</label>
                <input id="domain" v-model="site.domain" class="input" :placeholder="$t('photos.mafamille.fr')" autocomplete="off" />
                <p v-if="errors.domain" class="error">{{ errors.domain }}</p>
                <div class="tip mt-3">
                  <strong>{{ $t('À préparer avant la fin de l\'installation :') }}</strong>
                  <ol class="list-decimal pl-5 mt-1 space-y-1">
                    <li>
                      <Trans text="Chez votre fournisseur de domaine : un enregistrement :a de :domain vers l'adresse IP publique de ce serveur (ou de votre box).">
                        <template #a><code>A</code></template>
                        <template #domain><code>{{ site.domain || $t('photos.mafamille.fr') }}</code></template>
                      </Trans>
                    </li>
                    <li>
                      <Trans text="Sur votre box ou pare-feu : les ports :p80 et :p443 redirigés vers ce serveur.">
                        <template #p80><code>80</code></template>
                        <template #p443><code>443</code></template>
                      </Trans>
                    </li>
                    <li>{{ $t('Rien d\'autre : le certificat est demandé à la première visite.') }}</li>
                  </ol>
                </div>
              </div>
              <label class="choice" :class="{ on: site.access === 'proxy' }">
                <input v-model="site.access" type="radio" value="proxy" class="sr-only" />
                <span class="choice-title">{{ $t('J\'ai déjà un proxy inverse') }}</span>
                <span class="choice-text">
                  <Trans text="Traefik, Nginx Proxy Manager, Caddy, Synology… Faites-le pointer vers :url et indiquez l'adresse publique."><template #url><code>{{ state.suggestedUrl }}</code></template></Trans>
                </span>
              </label>
              <div v-if="site.access === 'proxy'" class="pl-1 sm:pl-4">
                <label class="label" for="url">{{ $t('Adresse publique') }}</label>
                <input id="url" v-model="site.url" class="input" :placeholder="'https://' + $t('photos.mafamille.fr')" />
                <p v-if="errors.url" class="error">{{ errors.url }}</p>
              </div>
            </div>

            <div class="grid sm:grid-cols-2 gap-6 mt-8">
              <div>
                <label class="label" for="tz">{{ $t('Fuseau horaire') }}</label>
                <p class="hint">{{ $t('Pour les dates des photos, les anniversaires et les envois du matin.') }}</p>
                <select id="tz" v-model="site.timezone" class="input">
                  <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                </select>
              </div>
              <div>
                <span class="label">{{ $t('Pour qui ?') }}</span>
                <p class="hint">{{ $t('Change le vocabulaire et les fonctions mises en avant. On peut ensuite créer d\'autres cercles.') }}</p>
                <div class="flex gap-2">
                  <button type="button" class="seg" :class="{ on: site.mode === 'family' }" @click="site.mode = 'family'">{{ $t('Une famille') }}</button>
                  <button type="button" class="seg" :class="{ on: site.mode === 'friends' }" @click="site.mode = 'friends'">{{ $t('Des amis') }}</button>
                </div>
              </div>
            </div>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('checks')">{{ $t('Retour') }}</button>
              <button type="button" class="btn-primary" :disabled="busy" @click="saveSite">{{ $t('Enregistrer et continuer') }}</button>
            </div>
          </section>

          <!-- ============ 3. Photos et vidéos ============ -->
          <section v-if="current === 'storage'" class="card">
            <h1 class="title">{{ $t('Où ranger les photos et vidéos ?') }}</h1>
            <p class="lead">{{ $t('MemoryLane range chaque fichier dans un « stockage objet » (le standard S3). Il peut être sur ce serveur, ou chez un fournisseur spécialisé. La base de données (albums, personnes, dates…) reste toujours sur ce serveur.') }}</p>
            <div class="grid gap-3 mt-6">
              <label class="choice" :class="{ on: storage.mode === 'local' }">
                <input v-model="storage.mode" type="radio" value="local" class="sr-only" />
                <span class="choice-title">{{ $t('Sur ce serveur') }} <span class="badge">{{ $t('recommandé pour commencer') }}</span></span>
                <span class="choice-text">
                  <Trans text="Gratuit et rien à créer : un petit serveur de stockage (Garage) est déjà prêt. Vos photos ne quittent pas la maison. Limites : l'espace du disque, et :lost sans sauvegarde (étape suivante). Pour un grand disque séparé, voir :var dans le fichier :file.">
                    <template #lost><strong>{{ $t('si ce disque lâche, tout est perdu') }}</strong></template>
                    <template #var><code>GARAGE_DATA_DIR</code></template>
                    <template #file><code>.env</code></template>
                  </Trans>
                </span>
              </label>
              <label class="choice" :class="{ on: storage.mode === 's3' }">
                <input v-model="storage.mode" type="radio" value="s3" class="sr-only" />
                <span class="choice-title">{{ $t('Chez un fournisseur S3') }}</span>
                <span class="choice-text">{{ $t('L\'intérêt : chaque fichier y est copié plusieurs fois dans des centres de données (pertes quasi impossibles), l\'espace est illimité, et changer de serveur ne demande pas de déménager les photos. Le coût : quelques euros par mois pour quelques centaines de Go (Scaleway, OVHcloud, Backblaze, AWS…).') }}</span>
              </label>
            </div>
            <div v-if="storage.mode === 's3'" class="mt-6">
              <p class="hint">
                <Trans text="Créez d'abord chez le fournisseur un :bucket et une :key qui peut y lire et écrire. Raccourcis :">
                  <template #bucket><strong>{{ $t('bucket privé') }}</strong></template>
                  <template #key><strong>{{ $t('clé d\'accès') }}</strong></template>
                </Trans>
              </p>
              <div class="flex flex-wrap gap-2 mb-4">
                <button v-for="p in S3_PRESETS" :key="p.name" type="button" class="chip" @click="preset(storage, p)">{{ p.name }}</button>
              </div>
              <S3Fields :model="storage" :errors="errors" :has-secret="state.storage.hasSecret" />
            </div>
            <Report :report="storageReport" />
            <p v-if="errors.test" class="error">{{ errors.test }}</p>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('site')">{{ $t('Retour') }}</button>
              <button type="button" class="btn-secondary" :disabled="busy" @click="saveStorage(true)">{{ $t('Tester') }}</button>
              <button type="button" class="btn-primary" :disabled="busy" @click="saveStorage(false)">{{ $t('Tester et continuer') }}</button>
            </div>
            <p class="hint mt-4">{{ $t('Changer de stockage plus tard ne déplace pas les fichiers déjà envoyés : mieux vaut choisir maintenant.') }}</p>
          </section>

          <!-- ============ 4. E-mails ============ -->
          <section v-if="current === 'mail'" class="card">
            <h1 class="title">{{ $t('E-mails') }}</h1>
            <p class="lead">{{ $t('MemoryLane envoie des e-mails pour inviter la famille, se connecter sans mot de passe (lien magique), réinitialiser un mot de passe, et raconter la semaine (« lettre du dimanche »).') }}</p>
            <div class="grid gap-3 mt-6">
              <label class="choice" :class="{ on: mail.mode === 'log' }">
                <input v-model="mail.mode" type="radio" value="log" class="sr-only" />
                <span class="choice-title">{{ $t('Pas d\'e-mails pour l\'instant') }}</span>
                <span class="choice-text">{{ $t('Les invitations se font en copiant un lien à envoyer vous-même (SMS, messagerie). On se connecte avec un mot de passe. Les e-mails sont seulement notés dans le journal du serveur.') }}</span>
              </label>
              <label class="choice" :class="{ on: mail.mode === 'smtp' }">
                <input v-model="mail.mode" type="radio" value="smtp" class="sr-only" />
                <span class="choice-title">{{ $t('Envoyer par un serveur SMTP') }}</span>
                <span class="choice-text">{{ $t('Votre messagerie (Gmail avec un mot de passe d\'application, OVH, Infomaniak…) ou un service d\'envoi (Brevo, Scaleway TEM, Mailjet…).') }}</span>
              </label>
            </div>
            <div v-if="mail.mode === 'smtp'" class="mt-6">
              <div class="flex flex-wrap gap-2 mb-4">
                <button v-for="p in SMTP_PRESETS" :key="p.name" type="button" class="chip" @click="Object.assign(mail, { host: p.host, port: p.port })">{{ p.name }}</button>
              </div>
              <p v-if="mail.host === 'smtp.gmail.com'" class="tip mb-4">{{ $t('Gmail : activez la validation en deux étapes, puis créez un « mot de passe d\'application » (Compte Google → Sécurité). Utilisez-le ici, pas votre mot de passe habituel.') }}</p>
              <div class="grid sm:grid-cols-[minmax(0,1fr)_8rem] gap-4">
                <div><label class="label" for="mh">{{ $t('Serveur SMTP') }}</label><input id="mh" v-model="mail.host" class="input" :placeholder="$t('smtp.exemple.fr')" /><p v-if="errors.host" class="error">{{ errors.host }}</p></div>
                <div><label class="label" for="mp">{{ $t('Port') }}</label><input id="mp" v-model.number="mail.port" class="input" type="number" /><p class="hint">{{ $t('587 ou 465') }}</p></div>
                <div><label class="label" for="mu">{{ $t('Identifiant') }}</label><input id="mu" v-model="mail.username" class="input" autocomplete="off" /></div>
                <div><label class="label" for="mpw">{{ $t('Mot de passe') }}</label><input id="mpw" v-model="mail.password" class="input" type="password" autocomplete="new-password" :placeholder="state.mail.hasPassword ? $t('inchangé') : ''" /></div>
                <div><label class="label" for="mf">{{ $t('Adresse d\'expédition') }}</label><input id="mf" v-model="mail.from_address" class="input" type="email" :placeholder="$t('souvenirs@mafamille.fr')" /><p v-if="errors.from_address" class="error">{{ errors.from_address }}</p></div>
                <div><label class="label" for="mn">{{ $t('Nom affiché') }}</label><input id="mn" v-model="mail.from_name" class="input" :placeholder="site.name" /></div>
              </div>
              <div class="mt-5 flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[14rem]"><label class="label" for="mt">{{ $t('Envoyer un e-mail de test à') }}</label><input id="mt" v-model="mail.test_to" class="input" type="email" :placeholder="$t('vous@exemple.fr')" /></div>
                <button type="button" class="btn-secondary" :disabled="busy || !mail.test_to" @click="saveMail(true)">{{ $t('Envoyer le test') }}</button>
              </div>
              <p v-if="mailSent" class="ok-text">{{ $t('E-mail envoyé : vérifiez la boîte de réception (et les indésirables).') }}</p>
            </div>
            <p v-if="errors.test" class="error">{{ errors.test }}</p>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('storage')">{{ $t('Retour') }}</button>
              <button type="button" class="btn-primary" :disabled="busy" @click="saveMail(false)">{{ $t('Enregistrer et continuer') }}</button>
            </div>
          </section>

          <!-- ============ 5. Sauvegardes ============ -->
          <section v-if="current === 'backup'" class="card">
            <h1 class="title">{{ $t('Sauvegardes') }}</h1>
            <p class="lead">{{ $t('Les photos de famille sont irremplaçables. La règle d\'or : trois copies, sur deux supports, dont une ailleurs qu\'à la maison.') }}</p>
            <div class="tip mt-4">
              <strong>{{ $t('Toujours actif :') }}</strong>
              {{ storage.mode === 'local'
                ? $t('chaque nuit, une copie de la base de données (albums, personnes, arbre, légendes) est rangée dans le stockage des photos, donc sur ce même serveur.')
                : $t('chaque nuit, une copie de la base de données (albums, personnes, arbre, légendes) est rangée dans le stockage des photos.') }}
            </div>
            <div class="grid gap-3 mt-6">
              <label class="choice" :class="{ on: backup.mode === 's3' }">
                <input v-model="backup.mode" type="radio" value="s3" class="sr-only" />
                <span class="choice-title">{{ $t('Copie chiffrée chaque nuit vers un stockage distant') }} <span class="badge">{{ $t('recommandé') }}</span></span>
                <span class="choice-text">{{ $t('Toutes les photos, vidéos, copies de la base et la configuration sont copiées, chiffrées (personne ne peut les lire chez le fournisseur), vers un bucket S3 d\'un autre fournisseur ou d\'un autre lieu. Un test de restauration a lieu chaque mois. Rien n\'est effacé de la sauvegarde quand une photo est supprimée.') }}</span>
              </label>
              <label class="choice" :class="{ on: backup.mode === 'none' }">
                <input v-model="backup.mode" type="radio" value="none" class="sr-only" />
                <span class="choice-title">{{ $t('Pas maintenant') }}</span>
                <span class="choice-text">{{ storage.mode === 'local'
                  ? $t('À vos risques : un disque en panne, un vol ou un incendie, et les photos sont perdues. Vous pourrez l\'activer plus tard.')
                  : $t('À vos risques : un disque en panne, un vol ou un incendie, et les photos restent chez le fournisseur, mais la base (albums, personnes) est perdue. Vous pourrez l\'activer plus tard.') }}</span>
              </label>
            </div>
            <div v-if="backup.mode === 's3' && !backupSecrets" class="mt-6">
              <p class="hint">
                <Trans text="Un bucket :dedicated, idéalement chez un autre fournisseur que les photos. Astuce : activez-y le verrouillage des objets (« Object Lock ») pour qu'aucune copie ne puisse être effacée pendant quelques semaines, même avec les clés.">
                  <template #dedicated><strong>{{ $t('dédié') }}</strong></template>
                </Trans>
              </p>
              <div class="flex flex-wrap gap-2 mb-4">
                <button v-for="p in S3_PRESETS" :key="p.name" type="button" class="chip" @click="preset(backup, p)">{{ p.name }}</button>
              </div>
              <S3Fields :model="backup" :errors="errors" :has-secret="state.backup.hasSecret" />
            </div>
            <Report :report="backupReport" />
            <div v-if="backupSecrets" class="mt-6 rounded-2xl border-2 border-amber-400 bg-amber-50 p-5">
              <h2 class="subtitle">{{ $t('Notez ces deux phrases secrètes maintenant') }}</h2>
              <p class="mt-1 text-[15px]">
                <Trans text="Elles chiffrent la sauvegarde. Sans elles, :nobody : gardez-les hors de ce serveur (gestionnaire de mots de passe, papier dans un tiroir).">
                  <template #nobody><strong>{{ $t('personne ne peut la relire, pas même vous') }}</strong></template>
                </Trans>
              </p>
              <dl class="mt-3 grid gap-2 font-mono text-[15px]">
                <div class="bg-white rounded-xl px-4 py-2.5 break-all"><dt class="text-xs font-sans text-stone-500">{{ $t('Mot de passe') }}</dt><dd>{{ backupSecrets.password }}</dd></div>
                <div class="bg-white rounded-xl px-4 py-2.5 break-all"><dt class="text-xs font-sans text-stone-500">{{ $t('Sel') }}</dt><dd>{{ backupSecrets.salt }}</dd></div>
              </dl>
              <label class="flex items-center gap-2 mt-4 font-medium"><input v-model="secretsNoted" type="checkbox" class="w-5 h-5" /> {{ $t('Je les ai notées en lieu sûr') }}</label>
            </div>
            <p v-if="errors.test" class="error">{{ errors.test }}</p>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('mail')">{{ $t('Retour') }}</button>
              <template v-if="backupSecrets">
                <button type="button" class="btn-primary" :disabled="!secretsNoted" @click="go('options')">{{ $t('Continuer') }}</button>
              </template>
              <template v-else>
                <button v-if="backup.mode === 's3'" type="button" class="btn-secondary" :disabled="busy" @click="saveBackup(true)">{{ $t('Tester') }}</button>
                <button type="button" class="btn-primary" :disabled="busy" @click="saveBackup(false)">{{ backup.mode === 's3' ? $t('Tester et continuer') : $t('Continuer sans sauvegarde') }}</button>
              </template>
            </div>
          </section>

          <!-- ============ 6. Options ============ -->
          <section v-if="current === 'options'" class="card">
            <h1 class="title">{{ $t('Options') }}</h1>
            <p class="lead">
              <Trans text="Tout est facultatif, et modifiable plus tard dans :file."><template #file><code>backend/.env</code></template></Trans>
            </p>

            <label class="toggle mt-6">
              <input v-model="options.faces" type="checkbox" />
              <span>
                <span class="choice-title">{{ $t('Reconnaissance des visages sur ce serveur') }}</span>
                <span class="choice-text">{{ $t('Repère les visages et propose les prénoms (jeu « Qui est-ce ? », fiches, arbre). Les photos ne quittent jamais le serveur. Demande environ 3 Go de mémoire et un processeur correct ; la première construction prend 5 à 15 minutes. Sans elle, les visages sont repérés dans le navigateur, plus lentement et moins finement.') }}</span>
              </span>
            </label>
            <label class="toggle mt-4" :class="{ 'opacity-50': !isHttps }">
              <input v-model="options.push" type="checkbox" :disabled="!isHttps" />
              <span>
                <span class="choice-title">{{ $t('Notifications sur les téléphones') }}</span>
                <span class="choice-text">{{ $t('Anniversaires, nouvelles photos d\'un proche, souvenirs du jour.') }} {{ isHttps ? $t('Les clés de chiffrement sont créées automatiquement.') : $t('Indisponible sans HTTPS (étape « Votre site »).') }}</span>
              </span>
            </label>

            <details class="mt-6 rounded-2xl border border-stone-200 bg-white p-5" :open="!!options.google_client_id">
              <summary class="cursor-pointer choice-title">{{ $t('Importer depuis Google Photos et Google Drive') }}</summary>
              <p class="choice-text mt-2">{{ $t('Demande un projet (gratuit) dans la console Google Cloud :') }}</p>
              <ol class="list-decimal pl-5 mt-2 space-y-1 text-[15px] text-stone-700">
                <li>
                  <Trans text="Sur :console, créez un projet et activez « Photos Picker API » et « Google Drive API ».">
                    <template #console><a href="https://console.cloud.google.com" target="_blank" rel="noopener" class="link">console.cloud.google.com</a></template>
                  </Trans>
                </li>
                <li>{{ $t('Écran de consentement OAuth : type « Externe », ajoutez les adresses des membres de la famille comme testeurs.') }}</li>
                <li>
                  <Trans text="Identifiants → « ID client OAuth » de type Application Web, avec l'URI de redirection :uri.">
                    <template #uri><code class="break-all">{{ state.site.url }}/auth/google/callback</code></template>
                  </Trans>
                </li>
                <li>{{ $t('Pour Drive, créez aussi une « clé API ».') }}</li>
              </ol>
              <div class="grid sm:grid-cols-2 gap-4 mt-4">
                <div><label class="label" for="gid">{{ $t('ID client') }}</label><input id="gid" v-model="options.google_client_id" class="input" autocomplete="off" /></div>
                <div><label class="label" for="gsec">{{ $t('Code secret du client') }}</label><input id="gsec" v-model="options.google_client_secret" class="input" type="password" autocomplete="new-password" :placeholder="state.options.hasGoogleSecret ? $t('inchangé') : ''" /></div>
                <div class="sm:col-span-2"><label class="label" for="gkey">{{ $t('Clé API (Drive)') }}</label><input id="gkey" v-model="options.google_drive_key" class="input" autocomplete="off" /></div>
              </div>
            </details>
            <details class="mt-4 rounded-2xl border border-stone-200 bg-white p-5" :open="!!options.healthcheck_url">
              <summary class="cursor-pointer choice-title">{{ $t('Être prévenu si le serveur tombe') }}</summary>
              <p class="choice-text mt-2">
                <Trans text="Chaque heure, MemoryLane vérifie sa santé (base, files d'attente, sauvegardes) et appelle une adresse de surveillance, par exemple chez :service (gratuit). Si l'appel n'arrive plus, vous recevez une alerte.">
                  <template #service><a href="https://healthchecks.io" target="_blank" rel="noopener" class="link">healthchecks.io</a></template>
                </Trans>
              </p>
              <label class="label mt-3" for="hc">{{ $t('Adresse « ping »') }}</label>
              <input id="hc" v-model="options.healthcheck_url" class="input" placeholder="https://hc-ping.com/…" />
              <p v-if="errors.healthcheck_url" class="error">{{ errors.healthcheck_url }}</p>
            </details>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('backup')">{{ $t('Retour') }}</button>
              <button type="button" class="btn-primary" :disabled="busy" @click="saveOptions">{{ $t('Enregistrer et continuer') }}</button>
            </div>
          </section>

          <!-- ============ 7. Compte ============ -->
          <section v-if="current === 'account'" class="card">
            <h1 class="title">{{ $t('Votre compte') }}</h1>
            <p class="lead">{{ $t('Vous serez l\'administrateur : vous inviterez la famille, créerez des cercles et pourrez tout régler. Votre fiche est créée dans l\'arbre, prête à accueillir parents et enfants.') }}</p>
            <div class="grid sm:grid-cols-2 gap-4 mt-6">
              <div><label class="label" for="fn">{{ $t('Prénom') }}</label><input id="fn" v-model="account.first_name" class="input" autocomplete="given-name" /><p v-if="errors.first_name" class="error">{{ errors.first_name }}</p></div>
              <div><label class="label" for="ln">{{ $t('Nom') }}</label><input id="ln" v-model="account.last_name" class="input" autocomplete="family-name" @input="suggestCircle" /><p v-if="errors.last_name" class="error">{{ errors.last_name }}</p></div>
              <div class="sm:col-span-2"><label class="label" for="em">{{ $t('Adresse e-mail') }}</label><p class="hint">{{ $t('C\'est votre identifiant de connexion.') }}</p><input id="em" v-model="account.email" class="input" type="email" autocomplete="email" /><p v-if="errors.email" class="error">{{ errors.email }}</p></div>
              <div><label class="label" for="pw">{{ $t('Mot de passe') }}</label><input id="pw" v-model="account.password" class="input" type="password" autocomplete="new-password" /><p class="hint">{{ $t('10 caractères au moins.') }}</p><p v-if="errors.password" class="error">{{ errors.password }}</p></div>
              <div><label class="label" for="pw2">{{ $t('Confirmation') }}</label><input id="pw2" v-model="account.password_confirmation" class="input" type="password" autocomplete="new-password" /></div>
              <div class="sm:col-span-2">
                <label class="label" for="ci">{{ site.mode === 'friends' ? $t('Nom de votre cercle d\'amis') : $t('Nom de votre famille') }}</label>
                <p class="hint">{{ $t('Le premier « cercle » : les personnes qui partageront leurs photos. D\'autres pourront suivre (belle-famille, amis…).') }}</p>
                <input id="ci" v-model="account.circle" class="input" @input="circleEdited = true" />
                <p v-if="errors.circle" class="error">{{ errors.circle }}</p>
              </div>
            </div>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('options')">{{ $t('Retour') }}</button>
              <button type="button" class="btn-primary" :disabled="busy" @click="saveAccount">{{ $t('Enregistrer et continuer') }}</button>
            </div>
          </section>

          <!-- ============ 8. Installation ============ -->
          <section v-if="current === 'install' && !result" class="card">
            <h1 class="title">{{ $t('Tout est prêt') }}</h1>
            <p class="lead">{{ $t('Vérifiez une dernière fois, puis lancez l\'installation.') }}</p>
            <dl class="mt-6 divide-y divide-stone-100 border border-stone-200 rounded-2xl bg-white">
              <div v-for="row in summary" :key="row[0]" class="flex flex-col sm:flex-row gap-1 sm:gap-6 px-4 py-3">
                <dt class="sm:w-48 shrink-0 text-stone-500">{{ row[0] }}</dt>
                <dd class="font-medium break-words min-w-0">{{ row[1] }}</dd>
              </div>
            </dl>
            <p v-if="installError" class="error mt-4">{{ installError }}</p>
            <div class="actions">
              <button type="button" class="btn-secondary" :disabled="busy" @click="go('account')">{{ $t('Retour') }}</button>
              <button type="button" class="btn-primary" :disabled="busy || missing.length > 0" @click="install">{{ busy ? $t('Installation en cours…') : $t('Installer MemoryLane') }}</button>
            </div>
            <p v-if="missing.length" class="error">{{ $t('Étapes à compléter : :steps.', { steps: missing.join(', ') }) }}</p>
          </section>

          <!-- ============ Terminé ============ -->
          <section v-if="result" class="card">
            <div class="text-5xl">🎉</div>
            <h1 class="title mt-2">{{ $t('C\'est installé !') }}</h1>
            <p class="lead">{{ result.profiles.includes('faces')
              ? $t('Le terminal termine le démarrage des services : comptez une minute, et 5 à 15 minutes pour la reconnaissance des visages.')
              : $t('Le terminal termine le démarrage des services : comptez une minute.') }}</p>
            <div class="mt-6 rounded-2xl bg-white border border-stone-200 p-5">
              <p class="text-[15px] text-stone-600">{{ $t('Votre MemoryLane :') }}</p>
              <p class="font-display text-2xl font-bold break-all">{{ result.url }}</p>
              <p class="text-[15px] text-stone-600 mt-1">{{ $t('Connexion : :email et votre mot de passe.', { email: result.email }) }}</p>
              <p v-if="result.profiles.includes('https')" class="hint mt-2">{{ $t('Le certificat HTTPS est demandé à la première visite : si la page ne répond pas, vérifiez le domaine (enregistrement A) et les ports 80/443.') }}</p>
              <a :href="result.url + '/login'" class="btn-primary inline-flex mt-4">{{ $t('Ouvrir MemoryLane') }}</a>
            </div>
            <h2 class="subtitle mt-8">{{ $t('Et ensuite ?') }}</h2>
            <ul class="mt-3 space-y-2 text-[15px] text-stone-700 list-disc pl-5">
              <li>{{ $t('Envoyez vos premières photos (bouton « + »), créez un album.') }}</li>
              <li>{{ $t('Invitez la famille depuis « Cercles » : chacun reçoit un lien pour créer son accès.') }}</li>
              <li>{{ $t('Sur téléphone, « Ajouter à l\'écran d\'accueil » installe MemoryLane comme une application.') }}</li>
              <li><Trans text="Mises à jour : :cmd sur le serveur."><template #cmd><code>./install.sh update</code></template></Trans></li>
            </ul>
          </section>
        </template>
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, h } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { t } from '@/i18n';
import Trans from '@/Components/Trans.vue';
import LocaleSwitch from '@/Components/LocaleSwitch.vue';

const props = defineProps({
  unlocked: Boolean,
  state: { type: Object, default: null },
});

const STEPS = [
  { key: 'checks', label: t('Vérifications') },
  { key: 'site', label: t('Votre site') },
  { key: 'storage', label: t('Photos et vidéos') },
  { key: 'mail', label: t('E-mails') },
  { key: 'backup', label: t('Sauvegardes') },
  { key: 'options', label: t('Options') },
  { key: 'account', label: t('Votre compte') },
  { key: 'install', label: t('Installation') },
];
const REQUIRED = ['site', 'storage', 'mail', 'backup', 'options', 'account'];
const STEP_LABELS = Object.fromEntries(STEPS.map((s) => [s.key, s.label]));

const S3_PRESETS = [
  { name: 'Scaleway (Paris)', endpoint: 'https://s3.fr-par.scw.cloud', region: 'fr-par' },
  { name: 'OVHcloud (Gravelines)', endpoint: 'https://s3.gra.io.cloud.ovh.net', region: 'gra' },
  { name: 'Backblaze B2 (Amsterdam)', endpoint: 'https://s3.eu-central-003.backblazeb2.com', region: 'eu-central-003' },
  { name: 'AWS (Paris)', endpoint: 'https://s3.eu-west-3.amazonaws.com', region: 'eu-west-3' },
];
const SMTP_PRESETS = [
  { name: 'Gmail', host: 'smtp.gmail.com', port: 587 },
  { name: 'OVH', host: 'ssl0.ovh.net', port: 465 },
  { name: 'Infomaniak', host: 'mail.infomaniak.com', port: 587 },
  { name: 'Brevo', host: 'smtp-relay.brevo.com', port: 587 },
  { name: 'Scaleway TEM', host: 'smtp.tem.scw.cloud', port: 587 },
];

const unlockedNow = ref(props.unlocked);
const state = ref(props.state);
const current = ref('checks');
const busy = ref(false);
const errors = reactive({});
const token = ref('');

const site = reactive({ name: 'MemoryLane', access: 'lan', domain: '', url: '', timezone: 'Europe/Paris', mode: 'family' });
const storage = reactive({ mode: 'local', endpoint: '', region: '', bucket: '', key: '', secret: '' });
const mail = reactive({ mode: 'log', host: '', port: 587, username: '', password: '', from_address: '', from_name: '', test_to: '' });
const backup = reactive({ mode: 's3', endpoint: '', region: '', bucket: '', key: '', secret: '' });
const options = reactive({ faces: false, push: false, google_client_id: '', google_client_secret: '', google_drive_key: '', healthcheck_url: '' });
const account = reactive({ first_name: '', last_name: '', email: '', password: '', password_confirmation: '', circle: '' });

const checks = ref(null);
const storageReport = ref(null);
const backupReport = ref(null);
const backupSecrets = ref(null);
const secretsNoted = ref(false);
const mailSent = ref(false);
const result = ref(null);
const installError = ref('');
// Nom du premier cercle proposé d'après le nom de famille, tant que la
// personne ne l'a pas écrit elle-même.
const circleEdited = ref(false);

const timezones = (() => {
  try {
    const all = Intl.supportedValuesOf('timeZone');
    return all.includes('Europe/Paris') ? all : ['Europe/Paris', ...all];
  } catch (e) {
    return ['Europe/Paris', 'Europe/Brussels', 'Europe/Zurich', 'Europe/Luxembourg', 'America/Montreal', 'Europe/London', 'UTC'];
  }
})();

const done = computed(() => new Set(state.value?.done || []));
const isDone = (key) => (key === 'checks' ? !!checks.value && !checks.value.blocking : done.value.has(key));
const stepIndex = computed(() => STEPS.findIndex((s) => s.key === current.value));
const stepInfo = computed(() => STEPS[stepIndex.value] || STEPS[0]);
const canGo = (key) => {
  const i = STEPS.findIndex((s) => s.key === key);
  return STEPS.slice(0, i).every((s) => isDone(s.key));
};
const missing = computed(() => REQUIRED.filter((k) => !done.value.has(k)).map((k) => STEP_LABELS[k]));
const isHttps = computed(() => String(state.value?.site?.url || '').startsWith('https://'));

const go = (key) => {
  if (!canGo(key)) return;
  Object.keys(errors).forEach((k) => delete errors[k]);
  current.value = key;
  window.scrollTo({ top: 0, behavior: 'smooth' });
};
const next = () => {
  const n = STEPS[stepIndex.value + 1];
  if (n) go(n.key);
};

// Formulaires pré-remplis avec ce qui est déjà enregistré (reprise possible).
const hydrate = (s) => {
  if (!s) return;
  state.value = s;
  Object.assign(site, s.site);
  if (s.site.access === 'proxy') site.url = s.site.url;
  Object.assign(storage, { ...s.storage, secret: '' });
  Object.assign(mail, { ...s.mail, password: '', test_to: mail.test_to });
  if (s.backup.endpoint || s.done.includes('backup')) Object.assign(backup, { ...s.backup, secret: '' });
  Object.assign(options, { ...s.options, google_client_secret: '' });
  if (s.account) {
    Object.assign(account, s.account);
    circleEdited.value = true; // reprise : garder le nom déjà choisi
  }
};

const call = async (method, url, data) => {
  busy.value = true;
  Object.keys(errors).forEach((k) => delete errors[k]);
  try {
    const res = await axios({ method, url, data });
    return res.data;
  } catch (e) {
    const r = e.response;
    if (r?.data?.errors) {
      for (const [k, v] of Object.entries(r.data.errors)) errors[k] = Array.isArray(v) ? v[0] : v;
    } else {
      errors.test = r?.data?.message || t('Le serveur ne répond pas. Vérifiez que install.sh tourne toujours.');
    }
    throw e;
  } finally {
    busy.value = false;
  }
};

const unlock = async () => {
  try {
    const data = await call('post', '/install/acces', { token: token.value });
    unlockedNow.value = true;
    hydrate(data.state);
    loadChecks();
  } catch (e) { /* message affiché */ }
};

const loadChecks = async () => {
  checks.value = null;
  try {
    checks.value = await call('get', '/install/verifications');
  } catch (e) {
    checks.value = { checks: [], blocking: true };
  }
};

const saveSite = async () => {
  try {
    hydrate((await call('post', '/install/site', { ...site })).state);
    next();
  } catch (e) { /* */ }
};

const preset = (target, p) => Object.assign(target, { endpoint: p.endpoint, region: p.region });

const saveStorage = async (testOnly) => {
  storageReport.value = null;
  try {
    const data = await call('post', '/install/stockage', { ...storage, test_only: testOnly });
    storageReport.value = data.report;
    if (!testOnly) {
      hydrate(data.state);
      setTimeout(next, 900);
    }
  } catch (e) {
    storageReport.value = e.response?.data?.steps ? e.response.data : null;
  }
};

const saveMail = async (testOnly) => {
  mailSent.value = false;
  try {
    const payload = { ...mail, test_only: testOnly };
    if (!testOnly) delete payload.test_to;
    const data = await call('post', '/install/emails', payload);
    if (testOnly) {
      mailSent.value = true;
    } else {
      hydrate(data.state);
      next();
    }
  } catch (e) { /* */ }
};

const saveBackup = async (testOnly) => {
  backupReport.value = null;
  try {
    const data = await call('post', '/install/sauvegardes', { ...backup, test_only: testOnly });
    backupReport.value = data.report || null;
    if (!testOnly) {
      hydrate(data.state);
      if (data.secrets) backupSecrets.value = data.secrets;
      else next();
    }
  } catch (e) {
    backupReport.value = e.response?.data?.steps ? e.response.data : null;
  }
};

const saveOptions = async () => {
  try {
    hydrate((await call('post', '/install/options', { ...options })).state);
    if (!account.circle) suggestCircle();
    next();
  } catch (e) { /* */ }
};

const suggestCircle = () => {
  if (circleEdited.value) return;
  account.circle = site.mode === 'friends' ? t('Les amis') : t('Famille :name', { name: account.last_name }).trim();
};

const saveAccount = async () => {
  try {
    hydrate((await call('post', '/install/compte', { ...account })).state);
    next();
  } catch (e) { /* */ }
};

const summary = computed(() => {
  const s = state.value;
  if (!s) return [];
  return [
    [t('Site'), `${s.site.name} — ${s.site.url}`],
    [t('Accès'), { lan: t('Depuis la maison'), domain: t('De partout, HTTPS automatique (:domain)', { domain: s.site.domain }), proxy: t('Par votre proxy inverse') }[s.site.access]],
    [t('Photos et vidéos'), s.storage.mode === 'local' ? t('Sur ce serveur (Garage)') : t(':bucket sur :endpoint', { bucket: s.storage.bucket, endpoint: s.storage.endpoint })],
    [t('E-mails'), s.mail.mode === 'smtp' ? `${s.mail.host} (${s.mail.from_address})` : t('Pas d\'envoi pour l\'instant')],
    [t('Sauvegarde distante'), s.backup.mode === 's3' ? t('Chiffrée, chaque nuit, vers :bucket', { bucket: s.backup.bucket }) : t('Aucune')],
    [t('Visages'), s.options.faces ? t('Sur ce serveur (InsightFace)') : t('Dans le navigateur')],
    [t('Notifications'), s.options.push ? t('Activées') : t('Désactivées')],
    [t('Administrateur'), s.account ? `${s.account.first_name} ${s.account.last_name} — ${s.account.email}` : '—'],
    [s.site.mode === 'friends' ? t('Premier cercle') : t('Famille'), s.account?.circle || '—'],
  ];
});

const install = async () => {
  installError.value = '';
  try {
    result.value = await call('post', '/install/terminer');
  } catch (e) {
    installError.value = e.response?.data?.message || t('L\'installation a échoué.');
  }
};

// ---- Petits composants -----------------------------------------------------
const S3Fields = (p) => {
  const field = (key, label, attrs = {}) => h('div', { class: attrs.wide ? 'sm:col-span-2' : '' }, [
    h('label', { class: 'label', for: `s3-${key}` }, label),
    h('input', {
      id: `s3-${key}`, class: 'input', value: p.model[key], autocomplete: 'off', type: attrs.type || 'text',
      placeholder: attrs.placeholder || '', onInput: (e) => { p.model[key] = e.target.value; },
    }),
    p.errors[key] ? h('p', { class: 'error' }, p.errors[key]) : null,
  ]);
  return h('div', { class: 'grid sm:grid-cols-2 gap-4' }, [
    field('endpoint', t('Adresse du service (endpoint)'), { wide: true, placeholder: 'https://s3.fr-par.scw.cloud' }),
    field('region', t('Région'), { placeholder: 'fr-par' }),
    field('bucket', t('Bucket'), { placeholder: t('souvenirs-famille') }),
    field('key', t('Clé d\'accès')),
    field('secret', t('Clé secrète'), { type: 'password', placeholder: p.hasSecret ? t('inchangée') : '' }),
  ]);
};
S3Fields.props = ['model', 'errors', 'hasSecret'];

const Report = (p) => (p.report ? h('div', { class: 'mt-5 rounded-2xl border border-stone-200 bg-white p-4 text-[15px]' }, [
  ...(p.report.steps || []).map((s) => h('div', { class: 'flex gap-2 text-emerald-700' }, ['✓ ', s])),
  p.report.warning ? h('p', { class: 'mt-2 text-amber-700' }, p.report.warning) : null,
]) : null);
Report.props = ['report'];

onMounted(() => {
  if (unlockedNow.value) {
    hydrate(props.state);
    loadChecks();
    // Reprise : on repart de la première étape non faite.
    const first = REQUIRED.find((k) => !done.value.has(k));
    if (first && done.value.size) current.value = first;
    else if (!first) current.value = 'install';
  }
});
</script>

<style scoped>
@reference "../../../css/app.css";
.card { @apply bg-white/70 rounded-3xl border border-stone-200 p-6 sm:p-10 shadow-sm; }
.title { @apply font-display text-3xl sm:text-4xl font-bold leading-tight; }
.subtitle { @apply font-display text-xl font-bold; }
.lead { @apply mt-3 text-[17px] text-stone-600 leading-relaxed max-w-3xl; }
.label { @apply block text-[15px] font-semibold mb-1; }
.hint { @apply text-sm text-stone-500 mb-2; }
.input { @apply w-full h-12 px-4 rounded-xl border border-stone-300 bg-white text-[16px] focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-amber-400; }
select.input { @apply pr-8; }
.error { @apply mt-1.5 text-sm text-red-700; }
.ok-text { @apply mt-2 text-sm text-emerald-700; }
.tip { @apply rounded-2xl bg-amber-50 border border-amber-200 px-4 py-3 text-[15px] text-stone-700 leading-relaxed; }
.choice { @apply block rounded-2xl border-2 border-stone-200 bg-white p-4 sm:p-5 cursor-pointer transition-colors hover:border-stone-300; }
.choice.on { @apply border-amber-500 bg-amber-50/60; }
.choice-title { @apply block font-semibold text-[16px]; }
.choice-text { @apply block mt-1 text-[15px] text-stone-600 leading-relaxed; }
.badge { @apply inline-block ml-1 px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 text-xs font-bold align-middle; }
.toggle { @apply flex items-start gap-4 rounded-2xl border border-stone-200 bg-white p-4 sm:p-5 cursor-pointer; }
.toggle input { @apply mt-1 w-5 h-5 shrink-0 accent-amber-600; }
.seg { @apply flex-1 h-12 rounded-xl border-2 border-stone-200 bg-white font-semibold; }
.seg.on { @apply border-amber-500 bg-amber-50; }
.chip { @apply px-3.5 py-1.5 rounded-full border border-stone-300 bg-white text-sm font-medium hover:bg-stone-50; }
.link { @apply text-amber-700 underline; }
.actions { @apply mt-8 flex flex-wrap justify-end gap-3; }
.btn-primary { @apply h-12 px-6 rounded-xl bg-amber-600 text-white font-semibold hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed items-center justify-center; }
.btn-secondary { @apply h-12 px-5 rounded-xl border border-stone-300 bg-white font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-50; }
code { @apply px-1.5 py-0.5 rounded bg-stone-100 text-[0.9em]; }
</style>
