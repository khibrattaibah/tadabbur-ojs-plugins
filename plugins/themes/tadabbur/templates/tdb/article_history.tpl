{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
<section class="item tdb_history">
	<h2 class="label">{translate key="plugins.themes.tadabbur.articleHistory"}</h2>
	<ol class="tdb_timeline">
		{if $tdbReceived}<li><span>{translate key="plugins.themes.tadabbur.received"}</span><time datetime="{$tdbReceived|date_format:"%Y-%m-%d"}">{$tdbReceived|date_format:$dateFormatShort}</time></li>{/if}
		{if $tdbAccepted}<li><span>{translate key="plugins.themes.tadabbur.accepted"}</span><time datetime="{$tdbAccepted|date_format:"%Y-%m-%d"}">{$tdbAccepted|date_format:$dateFormatShort}</time></li>{/if}
		{if $tdbPublished}<li><span>{translate key="plugins.themes.tadabbur.published"}</span><time datetime="{$tdbPublished|date_format:"%Y-%m-%d"}">{$tdbPublished|date_format:$dateFormatShort}</time></li>{/if}
	</ol>
	{if $tdbDoi}
		<button type="button" class="tdb_copy" data-copy="https://doi.org/{$tdbDoi|escape}" data-done="{translate|escape key="plugins.themes.tadabbur.copied"}">{translate key="plugins.themes.tadabbur.copyDoi"}</button>
	{/if}
</section>
