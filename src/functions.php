<?php

if ( ! function_exists( '__is' ) ) :
    /**
     * Use to write package specific code.
     * Set which package you current want using the 'WPA_SG_PACKAGE' variable.
     *
     * @return boolean [description]
     */
    function __is( $package ) {
        if ( 'source' === $package ) {
            return true;
        }

        return defined( 'WPA_SG_PACKAGE' ) && WPA_SG_PACKAGE === $package;
    }
endif;
