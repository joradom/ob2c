!function(e){e(document).ready((function(){e(".tooltip").tooltip(),e("#menu-btn").on("click",(function(o){o.preventDefault(),e("#side-menu").addClass("active"),e("body").addClass("active")})),e("#menu-btn-close").on("click",(function(){e("#side-menu").removeClass("active"),e("body").removeClass("active")})),e(".woocommerce-product-details__short-description").readmore({collapsedHeight:72,heightMargin:24,moreLink:'<a href="#">Lees meer</a>',lessLink:'<a href="#">Lees minder</a>'}),e(".header__item_search").on("click",(function(){e(".top-search_mobile").toggleClass("hidden")})),e(".toggle-filter").on("click",(function(){e("body").addClass("active"),e(".nm-shop-sidebar-default #nm-shop-sidebar").addClass("show-me")})),e(".close-filter").on("click",(function(){e("body").removeClass("active"),e(".nm-shop-sidebar-default #nm-shop-sidebar").removeClass("show-me")})),e(".cat-item.current-cat").on("click",(function(o){o.preventDefault(),alert("WIS DE HUIDIGE CATEGORIE"),e(this).children("a").attr("href","https://dev.oxfamwereldwinkels.be/oostende/producten/")}))})),e(document).mouseup((function(o){var s=e("#side-menu, .catalog-filters");s.is(o.target)||0!==s.has(o.target).length||(e("#side-menu").removeClass("active"),e("body").removeClass("active"),e(".nm-shop-sidebar-default #nm-shop-sidebar").removeClass("show-me"))}))}(jQuery);