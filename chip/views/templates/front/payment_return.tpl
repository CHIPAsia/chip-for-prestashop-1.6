{*
* CHIP for PrestaShop 1.6 - payment return message on the order confirmation page.
*}

<div class="box">
	<p class="text-center">
		<strong>{$chip_module_name|escape:'html':'UTF-8'}</strong>
	</p>
	<p class="text-center">
		{l s='Payment was successful. Thank you for your order.' mod='chip'}
		{if $chip_reference}
			<br />
			{l s='Order reference: %s' sprintf=[$chip_reference] mod='chip'}
		{/if}
	</p>
</div>
