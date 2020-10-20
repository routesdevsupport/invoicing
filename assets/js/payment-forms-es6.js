jQuery(function($) {

    /**
     * Simple throttle function
     * @param function callback The callback function
     * @param int limit The number of milliseconds to wait for
     */
    function gp_throttle (callback, limit) {

        // Ensure we have a limit.
        if ( ! limit ) {
            limit = 200
        }

        // Initially, we're not waiting
        var wait = false;

        // Ensure that the last call was handled
        var did_last = true;

        // We return a throttled function
        return function () {

            // If we're not waiting
            if ( ! wait ) {

                // We did the last action.
                did_last = true;

                // Execute users function
                callback.bind(this).call();

                // Prevent future invocations
                wait = true;

                // For a period of time...
                setTimeout(function () {

                    // then allow future invocations
                    wait = false;

                }, limit);

            // If we're waiting...
            } else {

                // We did not do the last action.
                did_last = false;

                // Wait for a period of time...
                var that = this
                setTimeout(function () {

                    // then ensure that we did the last call.
                    if ( ! did_last ) {
                        callback.bind(that).call();
                        did_last = true
                    }

                }, limit);

            }

        }
    }

    // Pass in a form to attach event listeners.
    window.getpaid_form = function( form ) {

        return {

            // Cache states to reduce server requests.
            cached_states: {},

            // The current form.
            form,

            // Alerts the user whenever an error occurs.
            show_error( error ) {

                // Display the error
                form.find( '.getpaid-payment-form-errors' ).html( error ).removeClass( 'd-none' )

                // Animate to the error
                $( 'html, body' ).animate({
                    scrollTop: form.find( '.getpaid-payment-form-errors' ).offset().top
                }, 500);

            },

            // Hides the current error.
            hide_error() {

                // Hide the error
                form.find( '.getpaid-payment-form-errors' ).html('').addClass('d-none')

            },

            // Caches a state.
            cache_state( key, state ) {
                this.cached_states[ key ] = state
            },

            // Returns the current cache key.
            current_state_key() {
                return this.form.serialize()
            },

            // Checks if the current state is cached.
            is_current_state_cached() {
                return this.cached_states.hasOwnProperty( this.current_state_key() )
            },

            // Switches to a given form state.
            switch_state() {

                // Hide any errors.
                this.hide_error()

                // Retrieve form state.
                var state = this.cached_states[ this.current_state_key() ]

                if ( ! state ) {
                    return this.fetch_state()
                }

                // Process totals.
                if ( state.totals ) {

                    for ( var total in state.totals ) {
                        if ( state.totals.hasOwnProperty( total ) ) {
                            this.form.find( '.getpaid-form-cart-totals-total-' + total ).html( state.totals[total] )
                        }
                    }

                }

                // Process item sub-totals.
                if ( state.items ) {

                    for ( var item in state.items ) {
                        if ( state.items.hasOwnProperty( item ) ) {
                            this.form.find( '.getpaid-form-cart-item-subtotal-' + item ).html( state.items[item] )
                        }
                    }

                }

                // Process text updates.
                if ( state.texts ) {

                    for ( var selector in state.texts ) {
                        if ( state.texts.hasOwnProperty( selector ) ) {
                            this.form.find( selector ).html( state.texts[selector] )
                        }
                    }

                }

                // Hide/Display Gateways.
                if ( state.gateways ) {
                    this.process_gateways( state.gateways, state )
                }

            },

            // Refreshes the state either from cache or from the server.
            refresh_state() {

                // If we have the state in the cache...
                if ( this.is_current_state_cached() ) {
                    return this.switch_state()
                }

                // ... else, fetch from the server.
                this.fetch_state()
            },

            // Fetch a state from the server, and applies it to the form.
            fetch_state() {

                // Block the form.
                wpinvBlock( this.form );

                // Return a promise.
                var key = this.current_state_key()
                return $.post( WPInv.ajax_url, key + '&action=wpinv_payment_form_refresh_prices&_ajax_nonce=' + WPInv.formNonce )

                .done( ( res ) => {

                    // If successful, cache the prices.
                    if ( res.success ) {
                        this.cache_state( key, res.data )
                        return this.switch_state()
                    }

                    // Else, display an error.
                    this.show_error( res )
                } )

                // Connection error.
                .fail( () => {
                    this.show_error( WPInv.connectionError )
                } )

                // Unblock the form.
                .always( () => {
                    this.form.unblock();
                })

            },

            // Updates the state field.
            update_state_field() {

                // Ensure that we have a state field.
                if ( this.form.find( '.wpinv_state' ).length ) {

                    var state = this.form.find( '.wpinv_state' ).parent()

                    wpinvBlock( state );

                    var data = {
                        action: 'wpinv_get_payment_form_states_field',
                        country: this.form.find( '.wpinv_country' ).val(),
                        form: this.form.find( 'input[name="form_id"]' ).val()
                    };

                    $.get(ajaxurl, data, ( res ) => {

                        if ( 'object' == typeof res ) {
                            state.html( res.data )
                        }

                    })

                    .always( () => {
                        state.unblock()
                    });

                }

            },

            // Attaches events to a form.
            attach_events() {

                // Cache the object.
                var that = this

                // Keeps the state in sync.
                var on_field_change = gp_throttle(
                    function() { that.refresh_state() },
                    500
                )

                // Refresh prices.
                this.form.on( 'input', '.getpaid-refresh-on-change', on_field_change );
                this.form.on( 'input', '.getpaid-payment-form-element-price_select :input:not(.getpaid-refresh-on-change)', on_field_change );
                this.form.on( 'input', '.getpaid-item-price-input', on_field_change );
                this.form.on( 'change', '.getpaid-item-quantity-input', on_field_change );
                this.form.on( 'change', '[name="getpaid-payment-form-selected-item"]', on_field_change);

                // Refresh when country changes.
                this.form.on( 'change', '.wpinv_country', () => {
                    this.update_state_field()
                    on_field_change()
                } );

                // Refresh when state changes.
                this.form.on( 'change', '.wpinv_state', () => {
                    on_field_change()
                } );

                // Watch for gateway clicks.
                this.form.on( 'change', '.getpaid-gateway-radio input', () => {
                    var gateway = this.form.find( '.getpaid-gateway-radio input:checked' ).val()
                    form.find( '.getpaid-gateway-description' ).slideUp();
                    form.find( `.getpaid-description-${gateway}` ).slideDown();
                } );

            },

            // Processes gateways
            process_gateways( enabled_gateways, state ) {

                // Prepare the submit btn.
                var submit_btn = this.form.find( '.getpaid-payment-form-submit' )
                submit_btn.prop( 'disabled', false ).css('cursor', 'pointer')

                // If it's free, hide the gateways and display the free checkout text...
                if ( state.is_free ) {
                    submit_btn.val( submit_btn.data( 'free' ) )
                    this.form.find( '.getpaid-gateways' ).slideUp();
                    return
                }

                // ... else show, the gateways and the pay text.
                this.form.find( '.getpaid-gateways' ).slideDown();
                submit_btn.val( submit_btn.data( 'pay' ) );

                // Next, hide the no gateways errors and display the gateways div.
                this.form.find( '.getpaid-no-recurring-gateways, .getpaid-no-active-gateways' ).addClass( 'd-none' );
                this.form.find( '.getpaid-select-gateway-title-div, .getpaid-available-gateways-div, .getpaid-gateway-descriptions-div' ).removeClass( 'd-none' );

                // If there are no gateways?
                if ( enabled_gateways.length < 1 ) {

                    this.form.find( '.getpaid-select-gateway-title-div, .getpaid-available-gateways-div, .getpaid-gateway-descriptions-div' ).addClass( 'd-none' );
                    submit_btn.prop( 'disabled', true ).css('cursor', 'not-allowed');

                    if ( state.has_recurring ) {
                        this.form.find( '.getpaid-no-recurring-gateways' ).removeClass( 'd-none' );
                        return
                    }

                    this.form.find( '.getpaid-no-active-gateways' ).removeClass( 'd-none' );
                    return

                }

                // If only one gateway available, hide the radio button.
                if ( enabled_gateways.length == 1 ) {
                    this.form.find( '.getpaid-select-gateway-title-div' ).addClass( 'd-none' );
                    this.form.find( '.getpaid-gateway-radio input' ).addClass( 'd-none' );
                } else {
                    this.form.find( '.getpaid-gateway-radio input' ).removeClass( 'd-none' );
                }

                // Hide all visible payment methods.
                this.form.find( '.getpaid-gateway' ).addClass( 'd-none' );

                // Display enabled gateways.
                $.each( enabled_gateways, ( index, value ) => {
                    this.form.find( `.getpaid-gateway-${value}` ).removeClass( 'd-none' );
                })

                // If there is no gateway selected, select the first.
                if ( 0 === this.form.find( '.getpaid-gateway:visible input:checked' ).length ) {
                    this.form.find( '.getpaid-gateway:visible .getpaid-gateway-radio input' ).eq(0).prop( 'checked', true );
                }

                // Trigger change event for selected gateway.
                if ( 0 === this.form.find( '.getpaid-gateway-description:visible' ).length ) {
                    this.form.find( '.getpaid-gateway-radio input:checked' ).trigger('change');
                }

            },

            // Sets up payment tokens.
            setup_saved_payment_tokens() {

                // For each saved payment tokens list
                this.form.find( '.getpaid-saved-payment-methods' ).each( function() {

                var list = $( this )

                // When the payment method changes...
                $( 'input', list ).on( 'change', function() {
    
                    if ( $( this ).closest( 'li' ).hasClass( 'getpaid-new-payment-method' ) ) {
                        list.closest( '.getpaid-gateway-description' ).find( '.getpaid-new-payment-method-form' ).slideDown();
                    } else {
                        list.closest( '.getpaid-gateway-description' ).find( '.getpaid-new-payment-method-form' ).slideUp();
                    }

                })

                // Hide the list if there are no saved payment methods.
                if ( list.data( 'count' ) == '0' ) {
                    list.hide()
                }

                // If non is selected, select first.
                if ( 0 === $( 'input', list ).filter(':checked').length ) {
                    $( 'input', list ).eq(0).prop( 'checked', true );
                }

                // Trigger change event for selected method.
                $( 'input', list ).filter( ':checked' ).trigger( 'change' );

        })

            },

            // Inits a form.
            init() {

                this.setup_saved_payment_tokens()
                this.attach_events()
                this.refresh_state()

                // Trigger setup event.
                $( 'body' ).trigger( 'getpaid_setup_payment_form', [this.form] );
            },
        }

    }

    /**
     * Set's up a payment form for use.
     *
     * @param {string} form 
     * @TODO Move this into the above class.
     */
    var setup_form = function( form ) {

        // Add the row class to gateway credit cards.
        form.find('.getpaid-gateway-description-div .form-horizontal .form-group').addClass('row')

        // Hides items that are not in an array.
        /**
         * @param {Array} selected_items The items to display.
         */
        function filter_form_cart( selected_items ) {

            // Abort if there is no cart.
            if ( 0 == form.find( ".getpaid-payment-form-items-cart" ).length ) {
                return;
            }

            // Hide all selectable items.
            form.find('.getpaid-payment-form-items-cart-item.getpaid-selectable').each( function() {
                $( this ).find('.getpaid-item-price-input').attr( 'name', '' )
                $( this ).find('.getpaid-item-quantity-input').attr( 'name', '' )
                $( this ).hide()
            })

            // Display selected items.
            $( selected_items ).each( function( index, item_id ) {
        
                if ( item_id ) {
                    var item = form.find('.getpaid-payment-form-items-cart-item.item-' + item_id )
                    item.find('.getpaid-item-price-input').attr( 'name', 'getpaid-items[' + item_id + '][price]' )
                    item.find('.getpaid-item-quantity-input').attr( 'name', 'getpaid-items[' + item_id + '][quantity]' )
                    item.show()
                }

            })

        }

        // Radio select items.
        if ( form.find('.getpaid-payment-form-items-radio').length ) {

            // Hides displays the checked items.
            var filter_totals = function() {
                var selected_item = form.find(".getpaid-payment-form-items-radio .form-check-input:checked").val();
                filter_form_cart([selected_item])
            }

            // Do this when the value changes.
            var radio_items = form.find('.getpaid-payment-form-items-radio .form-check-input')

            radio_items.on( 'change', filter_totals );

            // If there are none selected, select the first.
            if ( 0 === radio_items.filter(':checked').length ) {
                radio_items.eq(0).prop( 'checked', true );
            }

            // Filter on page load.
            filter_totals();
        }

        // Checkbox select items.
        if ( form.find('.getpaid-payment-form-items-checkbox').length ) {

            // Hides displays the checked items.
            var filter_totals = function() {
                var selected_items = form
                    .find('.getpaid-payment-form-items-checkbox input:checked')
                    .map( function(){
                        return $(this).val();
                    })
                    .get()

                filter_form_cart(selected_items)
            }

            // Do this when the value changes.
            var checkbox_items = form.find('.getpaid-payment-form-items-checkbox input')

            checkbox_items.on( 'change', filter_totals );

            // If there are none selected, select the first.
            if ( 0 === checkbox_items.filter(':checked').length ) {
                checkbox_items.eq(0).prop( 'checked', true );
            }

            // Filter on page load.
            filter_totals();
        }

        // "Select" select items.
        if ( form.find('.getpaid-payment-form-items-select').length ) {

            // Hides displays the selected items.
            var filter_totals = function() {
                var selected_item = form.find(".getpaid-payment-form-items-select select").val();
                filter_form_cart([selected_item])
            }

            // Do this when the value changes.
            var select_box = form.find(".getpaid-payment-form-items-select select")

            select_box.on( 'change', filter_totals );

            // If there are none selected, select the first.
            if ( ! select_box.val() ) {
                select_box.find("option:first").prop('selected','selected');
            }

            // Filter on page load.
            filter_totals();
        }

        // Refresh prices.
        getpaid_form( form ).init()

        // Discounts.
        if ( form.find('.getpaid-discount-field').length ) {

            // Refresh prices when the discount button is clicked.
            form.find('.getpaid-discount-button').on('click', function( e ) {
                e.preventDefault();
                refresh_prices( form )
            } );

            // Refresh prices when hitting enter key in the discount field.
            form.find('.getpaid-discount-field').on('keypress', function( e ) {
                if ( e.keyCode == '13' ) {
                    e.preventDefault();
                    refresh_prices( form )
                }
            } );

            // Refresh prices when the discount value changes.
            form.find('.getpaid-discount-field').on('change', function( e ) {
                refresh_prices( form )
            } );

        }

        // Submitting the payment form.
        form.on( 'submit', function( e ) {

            // Do not submit the form.
            e.preventDefault();

            // instead, display a loading indicator.
            wpinvBlock(form);

            // Hide any errors.
            form.find('.getpaid-payment-form-errors').html('').addClass('d-none')

            // Fetch the unique identifier for this form.
            var unique_key = form.data('key')

            // Save data to a global variable so that other plugins can alter it.
            var data = {
                'submit' : true,
                'delay'  : false,
                'data'   : form.serialize(),
                'form'   : form,
                'key'    : unique_key,
            }

            // Trigger submit event.
            $( 'body' ).trigger( 'getpaid_payment_form_before_submit', [data] );

            if ( ! data.submit ) {
                form.unblock();
                return;
            }

            // Handles the actual submission.
            var submit = function () {
                return $.post( WPInv.ajax_url, data.data + '&action=wpinv_payment_form&_ajax_nonce=' + WPInv.formNonce )
                    .done( function( res ) {

                        // An error occured.
                        if ( 'string' == typeof res ) {
                            form.find('.getpaid-payment-form-errors').html(res).removeClass('d-none')
                            return
                        }

                        // Redirect to the thank you page.
                        if ( res.success ) {

                            // Asume that the action is a redirect.
                            if ( ! res.data.action ) {
                                window.location.href = decodeURIComponent(res.data)
                            }

                            if ( 'auto_submit_form' == res.data.action ) {
                                form.parent().append( '<div class="getpaid-checkout-autosubmit-form">' + res.data.form + '</div>' )
                                $( '.getpaid-checkout-autosubmit-form form' ).submit()
                            }

                            return
                        }

                        form.find('.getpaid-payment-form-errors').html(res.data).removeClass('d-none')
        
                    } )

                    .fail( function( res ) {
                        form.find('.getpaid-payment-form-errors').html(WPInv.connectionError).removeClass('d-none')
                    } )

                    .always(() => {
                        form.unblock();
                    })

            }

            // Are we submitting after a delay?
            if ( data.delay ) {

                var local_submit = function() {

                    if ( ! data.submit ) {
                        form.unblock();
                    } else {
                        submit();
                    }

                    $('body').unbind( 'getpaid_payment_form_delayed_submit' + unique_key, local_submit )

                }

                $('body').bind( 'getpaid_payment_form_delayed_submit' + unique_key, local_submit )
                return;
            }

            // If not, submit immeadiately.
            submit()

        })

    }

    // Set up all active forms.
    $('.getpaid-payment-form').each( function() {
        setup_form( $( this ) );
    } )

    // Payment buttons.
    $( document ).on( 'click', '.getpaid-payment-button', function( e ) {

        // Do not submit the form.
        e.preventDefault();

        // Add the loader.
        $('#getpaid-payment-modal .modal-body')
            .html( '<div class="d-flex align-items-center justify-content-center"><div class="spinner-border" role="status"><span class="sr-only">Loading...</span></div></div>' )

        // Display the modal.
        $('#getpaid-payment-modal').modal()

        // Load the form via ajax.
        var data    = $( this ).data()
        data.action = 'wpinv_get_payment_form'

        $.get( WPInv.ajax_url, data, function (res) {
            $('#getpaid-payment-modal .modal-body').html( res )
            $('#getpaid-payment-modal').modal('handleUpdate')
            $('#getpaid-payment-modal .getpaid-payment-form').each( function() {
                setup_form( $( this ) );
            } )
        })

        .fail(function (res) {
            $('#getpaid-payment-modal .modal-body').html(WPInv.connectionError)
            $('#getpaid-payment-modal').modal('handleUpdate')
        })

    } )

});
