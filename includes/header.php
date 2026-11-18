<?php
$documentTitle = isset($pageTitle) && is_string($pageTitle) && $pageTitle !== ''
    ? $pageTitle
    : 'Digital Livestock Auction';
$documentDescription = isset($pageDescription) && is_string($pageDescription)
    ? $pageDescription
    : 'A digital livestock auction platform connecting farmers and buyers.';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= htmlspecialchars($documentDescription, ENT_QUOTES, 'UTF-8') ?>">
<title><?= htmlspecialchars($documentTitle, ENT_QUOTES, 'UTF-8') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">