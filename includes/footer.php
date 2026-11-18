<footer class="site-footer" role="contentinfo">
	<p>&copy; <?= date('Y') ?> Digital Livestock Auction. All rights reserved.</p>
</footer>
<?php
$currentScriptDirectory = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$nestedPageDirectories = ['admin', 'auction', 'auth', 'buyer', 'farmer'];
$assetPathPrefix = in_array($currentScriptDirectory, $nestedPageDirectories, true) ? '../' : '';
?>
<script src="<?= $assetPathPrefix ?>assets/js/app.js" defer></script>
<script src="<?= $assetPathPrefix ?>assets/js/validation.js" defer></script>
