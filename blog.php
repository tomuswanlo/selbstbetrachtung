<?php
declare(strict_types=1);

require __DIR__ . '/lib/BlogPosts.php';

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$slug = trim((string) ($_GET['post'] ?? ''));
$post = $slug !== '' ? BlogPosts::bySlug($slug) : null;
$posts = BlogPosts::all();

$baseUrl = 'https://selbstbetrachtung-online.de';
$canonical = $baseUrl . '/blog.php' . ($slug !== '' ? '?post=' . urlencode($slug) : '');

if ($slug !== '' && !$post) {
    http_response_code(404);
}

$pageTitle = $post
    ? $post['title'] . ' – Selbstbetrachtung Blog'
    : 'Blog – Selbstbetrachtung | Psychologische Beratung & Coaching';
$pageDescription = $post
    ? $post['excerpt']
    : 'Impulse zu innerer Klarheit, Selbstreflexion und persönlicher Entwicklung – vertieft aus kurzen Gedanken, die auch auf Instagram und Facebook geteilt werden.';
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($pageDescription) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:type" content="<?= $post ? 'article' : 'website' ?>">
<meta property="og:title" content="<?= e($post ? $post['title'] : 'Selbstbetrachtung Blog') ?>">
<meta property="og:description" content="<?= e($pageDescription) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:locale" content="de_DE">
<?php if ($post): ?>
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BlogPosting',
    'headline' => $post['title'],
    'description' => $post['excerpt'],
    'datePublished' => $post['date'],
    'inLanguage' => 'de',
    'mainEntityOfPage' => $canonical,
    'author' => ['@type' => 'Person', 'name' => 'Gabriele Küppers'],
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'Selbstbetrachtung',
        'url' => $baseUrl . '/',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>
<?php endif; ?>
<style>
  :root{
    --cream:#F4EFE7; --cream-light:#FBF8F2; --cream-dark:#EDE5D8;
    --ink:#2E3439; --ink-mute:#626B71;
    --gold:#D6A26A; --gold-dark:#B3813F;
    --font-head:"Lora","Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif;
    --font-body:"Mulish",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  }
  *{box-sizing:border-box;}
  body{ margin:0; background:var(--cream); color:var(--ink); font-family:var(--font-body); line-height:1.6; }
  a{ color:var(--gold-dark); }
  .site-header{
    display:flex; align-items:center; justify-content:space-between;
    padding:1rem 1.5rem; background:var(--cream-light); border-bottom:1px solid var(--cream-dark);
  }
  .site-header a{ color:var(--ink-mute); text-decoration:none; font-size:.95rem; }
  .site-header a:hover{ color:var(--ink); }
  .site-header .brand{ font-family:var(--font-head); font-weight:600; letter-spacing:.03em; color:var(--ink); text-decoration:none; }

  main{ max-width:720px; margin:0 auto; padding:2.5rem 1.25rem 4rem; }
  .eyebrow{ font-size:.78rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--gold-dark); margin:0 0 .6rem; }
  h1{ font-family:var(--font-head); font-weight:600; font-size:2rem; line-height:1.25; margin:0 0 .6rem; }
  .intro{ color:var(--ink-mute); margin-bottom:2rem; }

  /* --- Übersicht --- */
  .post-list{ display:flex; flex-direction:column; gap:1.25rem; }
  .post-card{
    display:block; background:var(--cream-light); border:1px solid var(--cream-dark); border-radius:16px;
    padding:1.5rem; text-decoration:none; color:inherit; transition:border-color .15s, transform .15s;
  }
  .post-card:hover{ border-color:var(--gold); transform:translateY(-2px); }
  .post-card time{ display:block; font-size:.82rem; color:var(--ink-mute); margin-bottom:.4rem; }
  .post-card h2{ font-family:var(--font-head); font-weight:600; font-size:1.3rem; margin:0 0 .5rem; color:var(--ink); }
  .post-card p{ margin:0; color:var(--ink-mute); }
  .post-card .more{ display:inline-block; margin-top:.75rem; font-weight:700; font-size:.88rem; color:var(--gold-dark); }
  .empty{ color:var(--ink-mute); }

  /* --- Artikel --- */
  article time{ display:block; font-size:.85rem; color:var(--ink-mute); margin-bottom:1.5rem; }
  article .body h2{ font-family:var(--font-head); font-weight:600; font-size:1.3rem; margin:2rem 0 .75rem; color:var(--ink); }
  article .body p{ margin:0 0 1.1rem; }
  article .body a{ text-decoration:underline; }
  .source-note{
    margin-top:2rem; padding:1rem 1.25rem; background:var(--cream-light); border:1px solid var(--cream-dark);
    border-radius:12px; font-size:.9rem; color:var(--ink-mute);
  }
  .cta-card{
    margin-top:2.5rem; padding:1.5rem; background:var(--ink); color:rgba(255,255,255,.9);
    border-radius:16px; text-align:center;
  }
  .cta-card h3{ font-family:var(--font-head); font-weight:600; margin:0 0 .5rem; color:#fff; }
  .cta-card a.btn{
    display:inline-block; margin-top:.75rem; background:var(--gold); color:#fff; text-decoration:none;
    font-weight:700; padding:.75rem 1.6rem; border-radius:999px;
  }
  .cta-card a.btn:hover{ background:var(--gold-dark); }
  .back-to-blog{ display:inline-block; margin-bottom:1.5rem; font-size:.9rem; }

  footer{ text-align:center; color:var(--ink-mute); font-size:.85rem; padding:2rem 1rem; }
</style>
</head>
<body>
  <header class="site-header">
    <a href="/">← Zur Startseite</a>
    <a class="brand" href="/blog.php">Selbstbetrachtung Blog</a>
  </header>

  <main>
  <?php if ($post): ?>

    <a class="back-to-blog" href="/blog.php">← Alle Artikel</a>
    <article>
      <p class="eyebrow">Blog</p>
      <h1><?= e($post['title']) ?></h1>
      <time datetime="<?= e($post['date']) ?>"><?= e((new DateTimeImmutable($post['date']))->format('d.m.Y')) ?></time>
      <div class="body"><?= $post['body'] ?></div>

      <?php if ($post['source_url']): ?>
        <p class="source-note">Ursprünglich als kurzer Beitrag geteilt auf <a href="<?= e($post['source_url']) ?>" target="_blank" rel="noopener"><?= e($post['source_label'] ?? 'Social Media') ?></a> – hier etwas ausführlicher.</p>
      <?php endif; ?>

      <div class="cta-card">
        <h3>Möchten Sie darüber sprechen?</h3>
        <p>Ein kostenloses, unverbindliches Erstgespräch ist der erste Schritt.</p>
        <a class="btn" href="/#kontakt">Kostenloses Erstgespräch anfragen</a>
      </div>
    </article>

  <?php else: ?>

    <p class="eyebrow">Blog</p>
    <h1>Impulse für mehr Klarheit</h1>
    <p class="intro">Gedanken zu innerer Ruhe, Selbstreflexion und persönlicher Entwicklung – oft angestoßen durch einen kurzen Beitrag auf Instagram oder Facebook und hier etwas vertieft.</p>

    <div class="post-list">
      <?php if (!$posts): ?>
        <p class="empty">Noch keine Artikel veröffentlicht – schauen Sie bald wieder vorbei.</p>
      <?php endif; ?>
      <?php foreach ($posts as $p): ?>
        <a class="post-card" href="/blog.php?post=<?= urlencode($p['slug']) ?>">
          <time datetime="<?= e($p['date']) ?>"><?= e((new DateTimeImmutable($p['date']))->format('d.m.Y')) ?></time>
          <h2><?= e($p['title']) ?></h2>
          <p><?= e($p['excerpt']) ?></p>
          <span class="more">Weiterlesen →</span>
        </a>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>
  </main>

  <footer>Selbstbetrachtung – Gabriele Küppers · Dachsweg 27, 41189 Mönchengladbach</footer>
</body>
</html>
