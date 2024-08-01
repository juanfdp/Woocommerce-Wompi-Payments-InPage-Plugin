(function($){
    'use strict';
    if( typeof Cookies.get('wompi_sessionId') === 'undefined' ){
        $wompi.initialize( function ( data, error ) {
            if ( error === null ) {
                Cookies.set('wompi_sessionId', data.sessionId, { expires: 90, path: '/' })
            }
        })
    }
})(jQuery); 