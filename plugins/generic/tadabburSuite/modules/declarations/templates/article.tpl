{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
<section class="item declarations" id="declarations" style="margin-top:1.5rem">
	<h2 class="label">{$declTitle|escape}</h2>
	<dl class="value" style="margin:0">
		{foreach from=$declStatements item=s}
			<dt style="font-weight:700;margin-top:.6rem">{$s.label|escape}</dt>
			<dd style="margin:0 0 .3rem">{$s.text|strip_unsafe_html|nl2br}</dd>
		{/foreach}
	</dl>
</section>
