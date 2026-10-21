<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Lettre du dimanche — MemoryLane</title>
    <style>
        body { margin: 0; background: #f6f1e9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #44403c; }
        .wrap { max-width: 480px; margin: 0 auto; padding: 48px 16px; }
        .brand { font-family: Georgia, 'Times New Roman', serif; font-size: 24px; color: #1c1917; text-align: center; margin-bottom: 24px; }
        .card { background: #fff; border: 1px solid #ede7dc; border-radius: 18px; padding: 28px; }
        h1 { font-family: Georgia, 'Times New Roman', serif; font-size: 22px; color: #1c1917; margin: 0 0 12px; font-weight: normal; }
        p { font-size: 15px; line-height: 1.6; margin: 0 0 18px; }
        button { background: #b45309; color: #fff; border: 0; border-radius: 10px; padding: 12px 20px; font-size: 15px; font-weight: bold; cursor: pointer; }
        button.secondary { background: #fff; color: #44403c; border: 1px solid #d6d3d1; }
        a { color: #b45309; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">✦ MemoryLane</div>
    <div class="card">
        @if ($done === 'unsubscribed')
            <h1>C'est noté, {{ $firstName }}.</h1>
            <p>Vous ne recevrez plus la lettre du dimanche. Les souvenirs de la famille restent bien sûr accessibles sur MemoryLane.</p>
            <form method="post" action="{{ $resubscribeUrl }}">@csrf<button type="submit" class="secondary">Finalement, je veux la recevoir</button></form>
        @elseif ($done === 'resubscribed' || ($subscribed && ! $done))
            @if ($done === 'resubscribed')
                <h1>Ravi de vous retrouver, {{ $firstName }} !</h1>
                <p>La lettre du dimanche reprend dès cette semaine.</p>
            @else
                <h1>Ne plus recevoir la lettre du dimanche ?</h1>
                <p>Chaque dimanche, elle vous donne les nouvelles photos, les anniversaires à venir et un petit coup de projecteur sur la famille.</p>
                <form method="post" action="{{ $unsubscribeUrl }}">@csrf<button type="submit">Me désabonner</button></form>
            @endif
        @else
            <h1>Vous êtes déjà désabonné.</h1>
            <p>Vous ne recevez plus la lettre du dimanche.</p>
            <form method="post" action="{{ $resubscribeUrl }}">@csrf<button type="submit" class="secondary">Me réabonner</button></form>
        @endif
        <p style="margin:18px 0 0; font-size:13px;"><a href="{{ url('/profile') }}">Gérer mes préférences</a></p>
    </div>
</div>
</body>
</html>
