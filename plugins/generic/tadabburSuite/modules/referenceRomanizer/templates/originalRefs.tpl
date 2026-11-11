{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
<section class="item references tdb_original_refs" lang="ar" dir="rtl">
	<details>
		<summary class="label" style="cursor:pointer">{translate key="plugins.generic.referenceRomanizer.originalRefs"}</summary>
		<div class="value">
			{foreach from=$rrOriginal item=line}
				<p>{$line|escape}</p>
			{/foreach}
		</div>
	</details>
</section>
{literal}<script>(function(){var s=document.currentScript.previousElementSibling;document.addEventListener('DOMContentLoaded',function(){var refs=[].slice.call(document.querySelectorAll('section.item.references')).filter(function(x){return x!==s});if(refs.length){var r=refs[refs.length-1];r.after(s);[].forEach.call(r.querySelectorAll('.value p, .value li'),function(p){p.setAttribute('dir','auto')})}})})();</script>{/literal}
