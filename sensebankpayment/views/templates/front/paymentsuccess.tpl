{extends file=$layout}
{block name="content_wrapper"}
    {block name="content"}
        <h1 class="page-heading">{l s='Your order has been successfully paid' mod='sensebankpayment'}</h1>
        <div class="box cheque-box">
            <p style="margin-top: 20px">
                {l s='Thankyou! Your order has been successfully paid' mod='sensebankpayment'}
            </p>
            {if isset($order_reference) && !empty($order_reference)}
                <p style="margin-top: 20px">
                    {l s='You can see the details of your order in the section ' mod='sensebankpayment'} <a
                            href="{$link->getPageLink('history')|escape:'htmlall':'UTF-8'}"> -
                        "{l s='Order history' mod='sensebankpayment'}"</a>

                </p>
                <p style="margin-top: 20px">
                    {l s='Unique CODE of your order: ' mod='sensebankpayment'} {$order_reference|escape:'htmlall':'UTF-8'}
                </p>
            {/if}
        </div>
    {/block}
{/block}



