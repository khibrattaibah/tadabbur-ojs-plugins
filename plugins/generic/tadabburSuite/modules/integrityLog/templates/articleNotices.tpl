{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
<section class="item integrity_notices" id="integrity-notices" style="margin-top:1.5rem">
	<h2 class="label">{$ilTitle|escape}</h2>
	{foreach from=$ilNotices item=n}
		<div style="border-inline-start:4px solid #7a4d00;padding:.4rem .9rem;margin:.6rem 0;background:#fbf8f2">
			<strong>{$n.type|escape}</strong> — {$n.date|escape}
			{if $n.doi}<div dir="ltr" style="text-align:start"><a href="https://doi.org/{$n.doi|escape}">https://doi.org/{$n.doi|escape}</a></div>{/if}
			<div>{$n.text|strip_unsafe_html|nl2br}</div>
		</div>
	{/foreach}
</section>
