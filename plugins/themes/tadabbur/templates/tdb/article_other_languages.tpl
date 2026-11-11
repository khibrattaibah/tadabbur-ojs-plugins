{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
{foreach from=$tdbOtherLangs item=ol}
<section class="item tdb_other_lang" lang="{$ol.locale|replace:"_":"-"}" dir="{$ol.dir}">
	<h2 class="label">{translate key="plugins.themes.tadabbur.inLanguage" language=$ol.langName}</h2>
	<h3 class="tdb_ol_title">{$ol.title|strip_unsafe_html}</h3>
	{if $ol.abstract}<div class="tdb_ol_abstract">{$ol.abstract|strip_unsafe_html}</div>{/if}
	{if $ol.keywords}<p class="tdb_ol_kw">{foreach from=$ol.keywords item=kw name=k}<span>{$kw|escape}</span>{/foreach}</p>{/if}
</section>
{/foreach}
