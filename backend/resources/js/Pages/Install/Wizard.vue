<template>
  <Head title="Installation" />
  <div class="min-h-screen bg-[#faf7f1] text-[#1c1917]">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-12 grid lg:grid-cols-[17rem_minmax(0,1fr)] gap-8">
      <!-- Étapes -->
      <aside class="lg:sticky lg:top-8 self-start">
        <div class="flex items-center gap-3 mb-6">
          <img src="/icons/icon-192.png" alt="" class="w-11 h-11 rounded-xl" />
          <div>
            <div class="font-display text-2xl font-bold leading-none">MemoryLane</div>
            <div class="text-sm text-stone-500 mt-1">Installation</div>
          </div>
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
            <span>Étape {{ stepIndex + 1 }} sur {{ STEPS.length }}</span>
          </div>
          <div class="h-1.5 rounded-full bg-stone-200 overflow-hidden">
            <div class="h-full bg-amber-500 transition-all" :style="{ width: ((stepIndex + 1) / STEPS.length) * 100 + '%' }"></div>
          </div>
        </div>
      </aside>

      <main class="min-w-0">
        <!-- ============ Code d'accès ============ -->
        <section v-if="!unlockedNow" class="card">
          <h1 class="title">Bienvenue !</h1>
          <p class="lead">Cet assistant va configurer votre MemoryLane, étape par étape. Chaque choix est expliqué, et la plupart pourront être changés plus tard.</p>
          <form class="mt-6 max-w-sm" @submit.prevent="unlock">
            <label class="label" for="token">Code d'accès</label>
            <p class="hint">Il est affiché dans le terminal où vous avez lancé <code>./install.sh</code>. Il prouve que c'est bien vous qui installez.</p>
            <input id="token" v-model="token" class="input text-2xl tracking-[.3em] font-bold uppercase text-center" maxlength="12" autocomplete="off" autofocus />
            <p v-if="errors.token" class="error">{{ errors.token }}</p>
            <button class="btn-primary mt-4 w-full" :disabled="busy || token.trim().length < 6">Commencer</button>
          </form>
        </section>

        <template v-else>
          <!-- ============ 1. Vérifications ============ -->
          <section v-if="current === 'checks'" class="card">
            <h1 class="title">Avant de commencer</h1>
            <p class="lead">MemoryLane rassemble les photos, vidéos et souvenirs de votre famille : albums, arbre généalogique, reconnaissance des visages, carte, frise de vie… sur <strong>votre</strong> serveur. Comptez une dizaine de minutes.</p>
            <div class="grid sm:grid-cols-2 gap-3 mt-6">
              <div class="tip"><strong>Vous aurez peut-être besoin de :</strong> un nom de domaine (pour y accéder de partout), un compte e-mail d'envoi (SMTP), un compte de stockage S3. Rien n'est obligatoire : sans eux, tout fonctionne à la maison.</div>
              <div class="tip"><strong>Rien ne part ailleurs</strong> que là où vous le décidez : pas de publicité, pas de suivi, pas de compte chez nous.</div>
            </div>
            <h2 class="subtitle mt-8">Vérification du serveur</h2>
            <p v-if="!checks" class="hint">Vérification en cours…</p>
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
              <button type="button" class="btn-secondary" @click="loadChecks">Revérifier</button>
              <button type="button" class="btn-primary" :disabled="!checks || checks.blocking" @click="go('site')">Continuer</button>
            </div>
            <p v-if="checks?.blocking" class="error">Un point bloquant doit être corrigé avant de continuer (voir l'aide ci-dessus).</p>
          </section>

          <!-- ============ 2. Votre site ============ -->
          <section v-if="current === 'site'" class="card">
            <h1 class="title">Votre site</h1>
            <p class="lead">Son nom, et comment votre famille y accédera.</p>

            <label class="label mt-6" for="name">Nom du site</label>
            <p class="hint">Affiché en haut des pages et dans les e-mails. Par exemple : « Les souvenirs des Martin ».</p>
            <input id="name" v-model="site.name" class="input" maxlength="60" />
            <p v-if="errors.name" class="error">{{ errors.name }}</p>

            <h2 class="subtitle mt-8">Comment y accédera-t-on ?</h2>
            <div class="grid gap-3 mt-3">
              <label class="choice" :class="{ on: site.access === 'lan' }">
                <input v-model="site.access" type="radio" value="lan" class="sr-only" />
                <span class="choice-title">Seulement depuis la maison</span>
                <span class="choice-text">Le plus simple : à l'adresse <code>{{ state.suggestedUrl }}</code>, sur votre réseau. Mais pas d'accès en vacances, et sans HTTPS, pas de notifications ni d'installation comme application sur les téléphones. Vous pourrez passer à un domaine plus tard.</span>
              </label>
              <label class="choice" :class="{ on: site.access === 'domain' }">
                <input v-model="site.access" type="radio" value="domain" class="sr-only" />
                <span class="choice-title">De partout, avec mon nom de domaine <span class="badge">HTTPS automatique</span></span>
                <span class="choice-text">Pour toute la famille, où qu'elle soit. Le certificat HTTPS est obtenu et renouvelé tout seul (Let's Encrypt, gratuit).</span>
              </label>
              <div v-if="site.access === 'domain'" class="pl-1 sm:pl-4">
                <label class="label" for="domain">Nom de domaine</label>
                <input id="domain" v-model="site.domain" class="input" placeholder="photos.mafamille.fr" autocomplete="off" />
                <p v-if="errors.domain" class="error">{{ errors.domain }}</p>
                <div class="tip mt-3">
                  <strong>À préparer avant la fin de l'installation :</strong>
                  <ol class="list-decimal pl-5 mt-1 space-y-1">
                    <li>Chez votre fournisseur de domaine : un enregistrement <code>A</code> de <code>{{ site.domain || 'photos.mafamille.fr' }}</code> vers l'adresse IP publique de ce serveur (ou de votre box).</li>
                    <li>Sur votre box ou pare-feu : les ports <code>80</code> et <code>443</code> redirigés vers ce serveur.</li>
                    <li>Rien d'autre : le certificat est demandé à la première visite.</li>
                  </ol>
                </div>
              </div>
              <label class="choice" :class="{ on: site.access === 'proxy' }">
                <input v-model="site.access" type="radio" value="proxy" class="sr-only" />
                <span class="choice-title">J'ai déjà un proxy inverse</span>
                <span class="choice-text">Traefik, Nginx Proxy Manager, Caddy, Synology… Faites-le pointer vers <code>{{ state.suggestedUrl }}</code> et indiquez l'adresse publique.</span>
              </label>
              <div v-if="site.access === 'proxy'" class="pl-1 sm:pl-4">
                <label class="label" for="url">Adresse publique</label>
                <input id="url" v-model="site.url" class="input" placeholder="https://photos.mafamille.fr" />
                <p v-if="errors.url" class="error">{{ errors.url }}</p>
              </div>
            </div>

            <div class="grid sm:grid-cols-2 gap-6 mt-8">
              <div>
                <label class="label" for="tz">Fuseau horaire</label>
                <p class="hint">Pour les dates des photos, les anniversaires et les envois du matin.</p>
                <select id="tz" v-model="site.timezone" class="input">
                  <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                </select>
              </div>
              <div>
                <span class="label">Pour qui ?</span>
                <p class="hint">Change le vocabulaire et les fonctions mises en avant. On peut ensuite créer d'autres cercles.</p>
                <div class="flex gap-2">
                  <button type="button" class="seg" :class="{ on: site.mode === 'family' }" @click="site.mode = 'family'">Une famille</button>
                  <button type="button" class="seg" :class="{ on: site.mode === 'friends' }" @click="site.mode = 'friends'">Des amis</button>
                </div>
              </div>
            </div>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('checks')">Retour</button>
              <button type="button" class="btn-primary" :disabled="busy" @click="saveSite">Enregistrer et continuer</button>
            </div>
          </section>

          <!-- ============ 3. Photos et vidéos ============ -->
          <section v-if="current === 'storage'" class="card">
            <h1 class="title">Où ranger les photos et vidéos ?</h1>
            <p class="lead">MemoryLane range chaque fichier dans un « stockage objet » (le standard S3). Il peut être sur ce serveur, ou chez un fournisseur spécialisé. La base de données (albums, personnes, dates…) reste toujours sur ce serveur.</p>
            <div class="grid gap-3 mt-6">
              <label class="choice" :class="{ on: storage.mode === 'local' }">
                <input v-model="storage.mode" type="radio" value="local" class="sr-only" />
                <span class="choice-title">Sur ce serveur <span class="badge">recommandé pour commencer</span></span>
                <span class="choice-text">Gratuit et rien à créer : un petit serveur de stockage (Garage) est déjà prêt. Vos photos ne quittent pas la maison. Limites : l'espace du disque, et <strong>si ce disque lâche, tout est perdu</strong> sans sauvegarde (étape suivante). Pour un grand disque séparé, voir <code>GARAGE_DATA_DIR</code> dans le fichier <code>.env</code>.</span>
              </label>
              <label class="choice" :class="{ on: storage.mode === 's3' }">
                <input v-model="storage.mode" type="radio" value="s3" class="sr-only" />
                <span class="choice-title">Chez un fournisseur S3</span>
                <span class="choice-text">L'intérêt : chaque fichier y est copié plusieurs fois dans des centres de données (pertes quasi impossibles), l'espace est illimité, et changer de serveur ne demande pas de déménager les photos. Le coût : quelques euros par mois pour quelques centaines de Go (Scaleway, OVHcloud, Backblaze, AWS…).</span>
              </label>
            </div>
            <div v-if="storage.mode === 's3'" class="mt-6">
              <p class="hint">Créez d'abord chez le fournisseur un <strong>bucket privé</strong> et une <strong>clé d'accès</strong> qui peut y lire et écrire. Raccourcis :</p>
              <div class="flex flex-wrap gap-2 mb-4">
                <button v-for="p in S3_PRESETS" :key="p.name" type="button" class="chip" @click="preset(storage, p)">{{ p.name }}</button>
              </div>
              <S3Fields :model="storage" :errors="errors" :has-secret="state.storage.hasSecret" />
            </div>
            <Report :report="storageReport" />
            <p v-if="errors.test" class="error">{{ errors.test }}</p>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('site')">Retour</button>
              <button type="button" class="btn-secondary" :disabled="busy" @click="saveStorage(true)">Tester</button>
              <button type="button" class="btn-primary" :disabled="busy" @click="saveStorage(false)">Tester et continuer</button>
            </div>
            <p class="hint mt-4">Changer de stockage plus tard ne déplace pas les fichiers déjà envoyés : mieux vaut choisir maintenant.</p>
          </section>

          <!-- ============ 4. E-mails ============ -->
          <section v-if="current === 'mail'" class="card">
            <h1 class="title">E-mails</h1>
            <p class="lead">MemoryLane envoie des e-mails pour inviter la famille, se connecter sans mot de passe (lien magique), réinitialiser un mot de passe, et raconter la semaine (« lettre du dimanche »).</p>
            <div class="grid gap-3 mt-6">
              <label class="choice" :class="{ on: mail.mode === 'log' }">
                <input v-model="mail.mode" type="radio" value="log" class="sr-only" />
                <span class="choice-title">Pas d'e-mails pour l'instant</span>
                <span class="choice-text">Les invitations se font en copiant un lien à envoyer vous-même (SMS, messagerie). On se connecte avec un mot de passe. Les e-mails sont seulement notés dans le journal du serveur.</span>
              </label>
              <label class="choice" :class="{ on: mail.mode === 'smtp' }">
                <input v-model="mail.mode" type="radio" value="smtp" class="sr-only" />
                <span class="choice-title">Envoyer par un serveur SMTP</span>
                <span class="choice-text">Votre messagerie (Gmail avec un mot de passe d'application, OVH, Infomaniak…) ou un service d'envoi (Brevo, Scaleway TEM, Mailjet…).</span>
              </label>
            </div>
            <div v-if="mail.mode === 'smtp'" class="mt-6">
              <div class="flex flex-wrap gap-2 mb-4">
                <button v-for="p in SMTP_PRESETS" :key="p.name" type="button" class="chip" @click="Object.assign(mail, { host: p.host, port: p.port })">{{ p.name }}</button>
              </div>
              <p v-if="mail.host === 'smtp.gmail.com'" class="tip mb-4">Gmail : activez la validation en deux étapes, puis créez un « mot de passe d'application » (Compte Google → Sécurité). Utilisez-le ici, pas votre mot de passe habituel.</p>
              <div class="grid sm:grid-cols-[minmax(0,1fr)_8rem] gap-4">
                <div><label class="label" for="mh">Serveur SMTP</label><input id="mh" v-model="mail.host" class="input" placeholder="smtp.exemple.fr" /><p v-if="errors.host" class="error">{{ errors.host }}</p></div>
                <div><label class="label" for="mp">Port</label><input id="mp" v-model.number="mail.port" class="input" type="number" /><p class="hint">587 ou 465</p></div>
                <div><label class="label" for="mu">Identifiant</label><input id="mu" v-model="mail.username" class="input" autocomplete="off" /></div>
                <div class="sm:col-span-1 col-span-1"><label class="label" for="mpw">Mot de passe</label><input id="mpw" v-model="mail.password" class="input" type="password" autocomplete="new-password" :placeholder="state.mail.hasPassword ? 'inchangé' : ''" /></div>
                <div><label class="label" for="mf">Adresse d'expédition</label><input id="mf" v-model="mail.from_address" class="input" type="email" placeholder="souvenirs@mafamille.fr" /><p v-if="errors.from_address" class="error">{{ errors.from_address }}</p></div>
                <div><label class="label" for="mn">Nom affiché</label><input id="mn" v-model="mail.from_name" class="input" :placeholder="site.name" /></div>
              </div>
              <div class="mt-5 flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[14rem]"><label class="label" for="mt">Envoyer un e-mail de test à</label><input id="mt" v-model="mail.test_to" class="input" type="email" placeholder="vous@exemple.fr" /></div>
                <button type="button" class="btn-secondary" :disabled="busy || !mail.test_to" @click="saveMail(true)">Envoyer le test</button>
              </div>
              <p v-if="mailSent" class="ok-text">E-mail envoyé : vérifiez la boîte de réception (et les indésirables).</p>
            </div>
            <p v-if="errors.test" class="error">{{ errors.test }}</p>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('storage')">Retour</button>
              <button type="button" class="btn-primary" :disabled="busy" @click="saveMail(false)">Enregistrer et continuer</button>
            </div>
          </section>

          <!-- ============ 5. Sauvegardes ============ -->
          <section v-if="current === 'backup'" class="card">
            <h1 class="title">Sauvegardes</h1>
            <p class="lead">Les photos de famille sont irremplaçables. La règle d'or : trois copies, sur deux supports, dont une ailleurs qu'à la maison.</p>
            <div class="tip mt-4">
              <strong>Toujours actif :</strong> chaque nuit, une copie de la base de données (albums, personnes, arbre, légendes) est rangée dans le stockage des photos{{ storage.mode === 'local' ? ', donc sur ce même serveur' : '' }}.
            </div>
            <div class="grid gap-3 mt-6">
              <label class="choice" :class="{ on: backup.mode === 's3' }">
                <input v-model="backup.mode" type="radio" value="s3" class="sr-only" />
                <span class="choice-title">Copie chiffrée chaque nuit vers un stockage distant <span class="badge">recommandé</span></span>
                <span class="choice-text">Toutes les photos, vidéos, copies de la base et la configuration sont copiées, chiffrées (personne ne peut les lire chez le fournisseur), vers un bucket S3 d'un autre fournisseur ou d'un autre lieu. Un test de restauration a lieu chaque mois. Rien n'est effacé de la sauvegarde quand une photo est supprimée.</span>
              </label>
              <label class="choice" :class="{ on: backup.mode === 'none' }">
                <input v-model="backup.mode" type="radio" value="none" class="sr-only" />
                <span class="choice-title">Pas maintenant</span>
                <span class="choice-text">À vos risques : un disque en panne, un vol ou un incendie, et les photos {{ storage.mode === 'local' ? 'sont perdues' : 'restent chez le fournisseur, mais la base (albums, personnes) est perdue' }}. Vous pourrez l'activer plus tard.</span>
              </label>
            </div>
            <div v-if="backup.mode === 's3' && !backupSecrets" class="mt-6">
              <p class="hint">Un bucket <strong>dédié</strong>, idéalement chez un autre fournisseur que les photos. Astuce : activez-y le verrouillage des objets (« Object Lock ») pour qu'aucune copie ne puisse être effacée pendant quelques semaines, même avec les clés.</p>
              <div class="flex flex-wrap gap-2 mb-4">
                <button v-for="p in S3_PRESETS" :key="p.name" type="button" class="chip" @click="preset(backup, p)">{{ p.name }}</button>
              </div>
              <S3Fields :model="backup" :errors="errors" :has-secret="state.backup.hasSecret" />
            </div>
            <Report :report="backupReport" />
            <div v-if="backupSecrets" class="mt-6 rounded-2xl border-2 border-amber-400 bg-amber-50 p-5">
              <h2 class="subtitle">Notez ces deux phrases secrètes maintenant</h2>
              <p class="mt-1 text-[15px]">Elles chiffrent la sauvegarde. Sans elles, <strong>personne ne peut la relire, pas même vous</strong> : gardez-les hors de ce serveur (gestionnaire de mots de passe, papier dans un tiroir).</p>
              <dl class="mt-3 grid gap-2 font-mono text-[15px]">
                <div class="bg-white rounded-xl px-4 py-2.5 break-all"><dt class="text-xs font-sans text-stone-500">Mot de passe</dt><dd>{{ backupSecrets.password }}</dd></div>
                <div class="bg-white rounded-xl px-4 py-2.5 break-all"><dt class="text-xs font-sans text-stone-500">Sel</dt><dd>{{ backupSecrets.salt }}</dd></div>
              </dl>
              <label class="flex items-center gap-2 mt-4 font-medium"><input v-model="secretsNoted" type="checkbox" class="w-5 h-5" /> Je les ai notées en lieu sûr</label>
            </div>
            <p v-if="errors.test" class="error">{{ errors.test }}</p>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('mail')">Retour</button>
              <template v-if="backupSecrets">
                <button type="button" class="btn-primary" :disabled="!secretsNoted" @click="go('options')">Continuer</button>
              </template>
              <template v-else>
                <button v-if="backup.mode === 's3'" type="button" class="btn-secondary" :disabled="busy" @click="saveBackup(true)">Tester</button>
                <button type="button" class="btn-primary" :disabled="busy" @click="saveBackup(false)">{{ backup.mode === 's3' ? 'Tester et continuer' : 'Continuer sans sauvegarde' }}</button>
              </template>
            </div>
          </section>

          <!-- ============ 6. Options ============ -->
          <section v-if="current === 'options'" class="card">
            <h1 class="title">Options</h1>
            <p class="lead">Tout est facultatif, et modifiable plus tard dans <code>backend/.env</code>.</p>

            <label class="toggle mt-6">
              <input v-model="options.faces" type="checkbox" />
              <span>
                <span class="choice-title">Reconnaissance des visages sur ce serveur</span>
                <span class="choice-text">Repère les visages et propose les prénoms (jeu « Qui est-ce ? », fiches, arbre). Les photos ne quittent jamais le serveur. Demande environ 3 Go de mémoire et un processeur correct ; la première construction prend 5 à 15 minutes. Sans elle, les visages sont repérés dans le navigateur, plus lentement et moins finement.</span>
              </span>
            </label>
            <label class="toggle mt-4" :class="{ 'opacity-50': !isHttps }">
              <input v-model="options.push" type="checkbox" :disabled="!isHttps" />
              <span>
                <span class="choice-title">Notifications sur les téléphones</span>
                <span class="choice-text">Anniversaires, nouvelles photos d'un proche, souvenirs du jour. {{ isHttps ? 'Les clés de chiffrement sont créées automatiquement.' : 'Indisponible sans HTTPS (étape « Votre site »).' }}</span>
              </span>
            </label>

            <details class="mt-6 rounded-2xl border border-stone-200 bg-white p-5" :open="!!options.google_client_id">
              <summary class="cursor-pointer choice-title">Importer depuis Google Photos et Google Drive</summary>
              <p class="choice-text mt-2">Demande un projet (gratuit) dans la console Google Cloud :</p>
              <ol class="list-decimal pl-5 mt-2 space-y-1 text-[15px] text-stone-700">
                <li>Sur <a href="https://console.cloud.google.com" target="_blank" rel="noopener" class="link">console.cloud.google.com</a>, créez un projet et activez « Photos Picker API » et « Google Drive API ».</li>
                <li>Écran de consentement OAuth : type « Externe », ajoutez les adresses des membres de la famille comme testeurs.</li>
                <li>Identifiants → « ID client OAuth » de type Application Web, avec l'URI de redirection <code class="break-all">{{ state.site.url }}/auth/google/callback</code>.</li>
                <li>Pour Drive, créez aussi une « clé API ».</li>
              </ol>
              <div class="grid sm:grid-cols-2 gap-4 mt-4">
                <div><label class="label" for="gid">ID client</label><input id="gid" v-model="options.google_client_id" class="input" autocomplete="off" /></div>
                <div><label class="label" for="gsec">Code secret du client</label><input id="gsec" v-model="options.google_client_secret" class="input" type="password" autocomplete="new-password" :placeholder="state.options.hasGoogleSecret ? 'inchangé' : ''" /></div>
                <div class="sm:col-span-2"><label class="label" for="gkey">Clé API (Drive)</label><input id="gkey" v-model="options.google_drive_key" class="input" autocomplete="off" /></div>
              </div>
            </details>
            <details class="mt-4 rounded-2xl border border-stone-200 bg-white p-5" :open="!!options.healthcheck_url">
              <summary class="cursor-pointer choice-title">Être prévenu si le serveur tombe</summary>
              <p class="choice-text mt-2">Chaque heure, MemoryLane vérifie sa santé (base, files d'attente, sauvegardes) et appelle une adresse de surveillance, par exemple chez <a href="https://healthchecks.io" target="_blank" rel="noopener" class="link">healthchecks.io</a> (gratuit). Si l'appel n'arrive plus, vous recevez une alerte.</p>
              <label class="label mt-3" for="hc">Adresse « ping »</label>
              <input id="hc" v-model="options.healthcheck_url" class="input" placeholder="https://hc-ping.com/…" />
              <p v-if="errors.healthcheck_url" class="error">{{ errors.healthcheck_url }}</p>
            </details>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('backup')">Retour</button>
              <button type="button" class="btn-primary" :disabled="busy" @click="saveOptions">Enregistrer et continuer</button>
            </div>
          </section>

          <!-- ============ 7. Compte ============ -->
          <section v-if="current === 'account'" class="card">
            <h1 class="title">Votre compte</h1>
            <p class="lead">Vous serez l'administrateur : vous inviterez la famille, créerez des cercles et pourrez tout régler. Votre fiche est créée dans l'arbre, prête à accueillir parents et enfants.</p>
            <div class="grid sm:grid-cols-2 gap-4 mt-6">
              <div><label class="label" for="fn">Prénom</label><input id="fn" v-model="account.first_name" class="input" autocomplete="given-name" /><p v-if="errors.first_name" class="error">{{ errors.first_name }}</p></div>
              <div><label class="label" for="ln">Nom</label><input id="ln" v-model="account.last_name" class="input" autocomplete="family-name" @input="suggestCircle" /><p v-if="errors.last_name" class="error">{{ errors.last_name }}</p></div>
              <div class="sm:col-span-2"><label class="label" for="em">Adresse e-mail</label><p class="hint">C'est votre identifiant de connexion.</p><input id="em" v-model="account.email" class="input" type="email" autocomplete="email" /><p v-if="errors.email" class="error">{{ errors.email }}</p></div>
              <div><label class="label" for="pw">Mot de passe</label><input id="pw" v-model="account.password" class="input" type="password" autocomplete="new-password" /><p class="hint">10 caractères au moins.</p><p v-if="errors.password" class="error">{{ errors.password }}</p></div>
              <div><label class="label" for="pw2">Confirmation</label><input id="pw2" v-model="account.password_confirmation" class="input" type="password" autocomplete="new-password" /></div>
              <div class="sm:col-span-2"><label class="label" for="ci">Nom de votre {{ site.mode === 'friends' ? 'cercle d\'amis' : 'famille' }}</label><p class="hint">Le premier « cercle » : les personnes qui partageront leurs photos. D'autres pourront suivre (belle-famille, amis…).</p><input id="ci" v-model="account.circle" class="input" /><p v-if="errors.circle" class="error">{{ errors.circle }}</p></div>
            </div>
            <div class="actions">
              <button type="button" class="btn-secondary" @click="go('options')">Retour</button>
              <button type="button" class="btn-primary" :disabled="busy" @click="saveAccount">Enregistrer et continuer</button>
            </div>
          </section>

          <!-- ============ 8. Installation ============ -->
          <section v-if="current === 'install' && !result" class="card">
            <h1 class="title">Tout est prêt</h1>
            <p class="lead">Vérifiez une dernière fois, puis lancez l'installation.</p>
            <dl class="mt-6 divide-y divide-stone-100 border border-stone-200 rounded-2xl bg-white">
              <div v-for="row in summary" :key="row[0]" class="flex flex-col sm:flex-row gap-1 sm:gap-6 px-4 py-3">
                <dt class="sm:w-48 shrink-0 text-stone-500">{{ row[0] }}</dt>
                <dd class="font-medium break-words min-w-0">{{ row[1] }}</dd>
              </div>
            </dl>
            <p v-if="installError" class="error mt-4">{{ installError }}</p>
            <div class="actions">
              <button type="button" class="btn-secondary" :disabled="busy" @click="go('account')">Retour</button>
              <button type="button" class="btn-primary" :disabled="busy || missing.length > 0" @click="install">{{ busy ? 'Installation en cours…' : 'Installer MemoryLane' }}</button>
            </div>
            <p v-if="missing.length" class="error">Étapes à compléter : {{ missing.join(', ') }}.</p>
          </section>

          <!-- ============ Terminé ============ -->
          <section v-if="result" class="card">
            <div class="text-5xl">🎉</div>
            <h1 class="title mt-2">C'est installé !</h1>
            <p class="lead">Le terminal termine le démarrage des services : comptez une minute{{ result.profiles.includes('faces') ? ', et 5 à 15 minutes pour la reconnaissance des visages' : '' }}.</p>
            <div class="mt-6 rounded-2xl bg-white border border-stone-200 p-5">
              <p class="text-[15px] text-stone-600">Votre MemoryLane :</p>
              <p class="font-display text-2xl font-bold break-all">{{ result.url }}</p>
              <p class="text-[15px] text-stone-600 mt-1">Connexion : {{ result.email }} et votre mot de passe.</p>
              <p v-if="result.profiles.includes('https')" class="hint mt-2">Le certificat HTTPS est demandé à la première visite : si la page ne répond pas, vérifiez le domaine (enregistrement A) et les ports 80/443.</p>
              <a :href="result.url + '/login'" class="btn-primary inline-flex mt-4">Ouvrir MemoryLane</a>
            </div>
            <h2 class="subtitle mt-8">Et ensuite ?</h2>
            <ul class="mt-3 space-y-2 text-[15px] text-stone-700 list-disc pl-5">
              <li>Envoyez vos premières photos (bouton « + »), créez un album.</li>
              <li>Invitez la famille depuis « Cercles » : chacun reçoit un lien pour créer son accès.</li>
              <li>Sur téléphone, « Ajouter à l'écran d'accueil » installe MemoryLane comme une application.</li>
              <li>Mises à jour : <code>./install.sh update</code> sur le serveur.</li>
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

const props = defineProps({
  unlocked: Boolean,
  state: { type: Object, default: null },
});

const STEPS = [
  { key: 'checks', label: 'Vérifications' },
  { key: 'site', label: 'Votre site' },
  { key: 'storage', label: 'Photos et vidéos' },
  { key: 'mail', label: 'E-mails' },
  { key: 'backup', label: 'Sauvegardes' },
  { key: 'options', label: 'Options' },
  { key: 'account', label: 'Votre compte' },
  { key: 'install', label: 'Installation' },
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
  if (s.account) Object.assign(account, s.account);
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
      errors.test = r?.data?.message || 'Le serveur ne répond pas. Vérifiez que install.sh tourne toujours.';
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
  if (!account.circle || account.circle.startsWith('Famille ') || account.circle.startsWith('Les amis')) {
    account.circle = site.mode === 'friends' ? 'Les amis' : `Famille ${account.last_name}`.trim();
  }
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
    ['Site', `${s.site.name} — ${s.site.url}`],
    ['Accès', { lan: 'Depuis la maison', domain: `De partout, HTTPS automatique (${s.site.domain})`, proxy: 'Par votre proxy inverse' }[s.site.access]],
    ['Photos et vidéos', s.storage.mode === 'local' ? 'Sur ce serveur (Garage)' : `${s.storage.bucket} sur ${s.storage.endpoint}`],
    ['E-mails', s.mail.mode === 'smtp' ? `${s.mail.host} (${s.mail.from_address})` : 'Pas d\'envoi pour l\'instant'],
    ['Sauvegarde distante', s.backup.mode === 's3' ? `Chiffrée, chaque nuit, vers ${s.backup.bucket}` : 'Aucune'],
    ['Visages', s.options.faces ? 'Sur ce serveur (InsightFace)' : 'Dans le navigateur'],
    ['Notifications', s.options.push ? 'Activées' : 'Désactivées'],
    ['Administrateur', s.account ? `${s.account.first_name} ${s.account.last_name} — ${s.account.email}` : '—'],
    [s.site.mode === 'friends' ? 'Premier cercle' : 'Famille', s.account?.circle || '—'],
  ];
});

const install = async () => {
  installError.value = '';
  try {
    result.value = await call('post', '/install/terminer');
  } catch (e) {
    installError.value = e.response?.data?.message || 'L\'installation a échoué.';
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
    field('endpoint', 'Adresse du service (endpoint)', { wide: true, placeholder: 'https://s3.fr-par.scw.cloud' }),
    field('region', 'Région', { placeholder: 'fr-par' }),
    field('bucket', 'Bucket', { placeholder: 'souvenirs-famille' }),
    field('key', 'Clé d\'accès'),
    field('secret', 'Clé secrète', { type: 'password', placeholder: p.hasSecret ? 'inchangée' : '' }),
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
