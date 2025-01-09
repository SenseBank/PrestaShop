<script>
  {literal}
    // add checkbox
    $(document).ready(() => {
        var chb_sensebankpayment_refund = '{/literal}{$chb_sensebankpayment_refund|escape:'htmlall':'UTF-8'}{literal}';
        var gateway_order_id = '{/literal}{$gateway_order_id|escape:'htmlall':'UTF-8'}{literal}';

        // Make partial order refund in Order page in BO
        $(document).on('click', '#desc-order-partial_refund', function(){

            // Create checkbox and insert for SensebankPayment refund
            if ($('#doPartialRefundSensebankPayment').length == 0) {
                let newCheckBox = `<p class="checkbox"><label for="doPartialRefundSensebankPayment">
                        <input type="checkbox" id="doPartialRefundSensebankPayment" name="doPartialRefundSensebankPayment" value="1" checked="checked" />
                          ${chb_sensebankpayment_refund} [${gateway_order_id}]</label></p>`;
                $('button[name=partialRefund]').parent('.partial_refund_fields').prepend(newCheckBox);
            }
        });

        $(document).on('click', '.partial-refund-display', function(){
            // Create checkbox and insert for SensebankPayment refund
            if ($('#doPartialRefundSensebankPayment').length == 0) {
                let newCheckBox = `
                        <div class="cancel-product-element form-group" style="display: block;">
                                <div class="checkbox">
                                    <div class="md-checkbox md-checkbox-inline">
                                      <label>
                                          <input type="checkbox" id="doPartialRefundSensebankPayment" name="doPartialRefundSensebankPayment" material_design="material_design" value="1" checked="checked" />
                                          <i class="md-checkbox-control"></i>
                                            ${chb_sensebankpayment_refund} [${gateway_order_id}]
                                        </label>
                                    </div>
                                </div>
                         </div>`;

                $('.refund-checkboxes-container').prepend(newCheckBox);
            }
        });
    });
  {/literal}
</script>