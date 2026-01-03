<?php
/**
 * MU Plugin: ProductFlow Manager
 * Author: Freelancer Ove
 * Version: 1.3.1
 */

if (!defined('ABSPATH')) exit;

/* ================= MENU ================= */
add_action('admin_menu', function () {
    add_menu_page(
        'Product Manager',
        'Product Manager',
        'manage_woocommerce',
        'productflow-manager',
        'pfm_render_page',
        'dashicons-products',
        55
    );
});

/* ================= ASSETS ================= */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_productflow-manager') return;

    wp_enqueue_script('jquery-ui-sortable');

    wp_add_inline_style('wp-admin', "
    #pfm-top,#pfm-bottom{
        position:sticky;
        background:#fff;
        z-index:999;
        padding:10px;
        display:flex;
        gap:10px;
        align-items:center;
        border-bottom:1px solid #ddd;
    }
    #pfm-top{top:32px}
    #pfm-bottom{
        bottom:0;
        border-top:1px solid #ddd;
        border-bottom:none;
    }
    .pfm-img img{width:40px}
    .pfm-serial{font-weight:bold}
    .row-actions{font-size:12px;color:#666}
    .pfm-star{
        font-size:18px;
        cursor:pointer;
        color:#ccc;
    }
    .pfm-star.active{color:#f5a623}
    ");

    wp_add_inline_script('jquery-ui-sortable', "
    jQuery(function($){
        let offset=0;
        const limit=10;
        const total=parseInt($('#pfm-total').val());

        function updateSerial(){
            $('#pfm-body tr').each(function(i){
                $(this).find('.pfm-serial').text(i+1);
            });
        }

        function counter(){
            $('#pfm-counter').text(offset+' of '+total);
            if(offset>=total) $('#pfm-load').hide();
        }

        function load(){
            $.post(ajaxurl,{
                action:'pfm_load',
                offset:offset,
                limit:limit,
                cat:$('#pfm-cat').val()
            },function(res){
                if(!res.trim()) return;
                $('#pfm-body').append(res);
                offset+=limit;
                updateSerial();
                counter();
            });
        }

        function reverse(){
            if(offset<=limit) return;
            offset-=limit;
            $('#pfm-body tr').slice(offset).remove();
            $('#pfm-load').show();
            updateSerial();
            counter();
        }

        load();

        $('#pfm-load').click(load);
        $('#pfm-reverse').click(reverse);

        $('#pfm-search').on('keyup',function(){
            let v=$(this).val().toLowerCase();
            $('#pfm-body tr').each(function(){
                $(this).toggle($(this).find('.pfm-name').text().toLowerCase().includes(v));
            });
        });

        $('#pfm-cat').change(function(){
            offset=0;
            $('#pfm-body').empty();
            $('#pfm-load').show();
            load();
        });

        $('#pfm-body').sortable({
            update:function(){
                updateSerial();
                let order=[];
                $('#pfm-body tr').each(function(){
                    order.push($(this).data('id'));
                });
                $.post(ajaxurl,{action:'pfm_sort',order});
            }
        });

        $('#pfm-apply').click(function(){
            let action=$('#pfm-bulk').val();
            let ids=[];
            $('.pfm-check:checked').each(function(){
                ids.push($(this).val());
            });
            if(!action || !ids.length) return;
            $.post(ajaxurl,{
                action:'pfm_bulk',
                bulk:action,
                ids:ids
            },()=>location.reload());
        });

        $(document).on('click','.pfm-star',function(){
            let el=$(this);
            let id=el.data('id');
            el.toggleClass('active');
            $.post(ajaxurl,{
                action:'pfm_toggle_featured',
                id:id
            });
        });
    });
    ");
});

/* ================= PAGE ================= */
function pfm_render_page(){
$total=wp_count_posts('product')->publish;
$cats=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
?>
<div class="wrap">
<h1>Product Manager</h1>
<input type="hidden" id="pfm-total" value="<?php echo $total; ?>">

<div id="pfm-top">
<select id="pfm-bulk">
<option value="">Bulk actions</option>
<option value="trash">Move to Trash</option>
</select>
<button id="pfm-apply" class="button">Apply</button>

<select id="pfm-cat">
<option value="">All Categories</option>
<?php foreach($cats as $c) echo "<option value='{$c->term_id}'>{$c->name}</option>"; ?>
</select>

<input id="pfm-search" placeholder="Search product name">
<a href="<?php echo admin_url('post-new.php?post_type=product'); ?>" class="button button-primary">Add New Product</a>
<strong id="pfm-counter">0 of <?php echo $total; ?></strong>
</div>

<table class="widefat striped">
<thead>
<tr>
<th><input type="checkbox" onclick="jQuery('.pfm-check').prop('checked',this.checked)"></th>
<th>#</th>
<th>Image</th>
<th>Name</th>
<th>Price</th>
<th>Stock</th>
<th>Categories</th>
<th>Featured</th>
<th>Video</th>
<th>Tags</th>
<th>Date</th>
</tr>
</thead>
<tbody id="pfm-body"></tbody>
</table>

<div id="pfm-bottom">
<button id="pfm-reverse" class="button">Reverse</button>
<button id="pfm-load" class="button button-primary">Load More</button>
</div>
</div>
<?php }

/* ================= AJAX ================= */
add_action('wp_ajax_pfm_load',function(){
$args=[
'post_type'=>'product',
'posts_per_page'=>intval($_POST['limit']),
'offset'=>intval($_POST['offset']),
'orderby'=>'menu_order',
'order'=>'ASC',
'no_found_rows'=>true
];
if($_POST['cat']){
$args['tax_query'][]=[
'taxonomy'=>'product_cat',
'terms'=>intval($_POST['cat'])
];
}
$q=new WP_Query($args);
$i=$_POST['offset']+1;

foreach($q->posts as $p){
$pr=wc_get_product($p->ID);
$price=$pr->get_price();
$price=$price!=='' ? wc_price($price) : '—';
$stock=$pr->is_in_stock() ? 'In stock ('.$pr->get_stock_quantity().')' : 'Out of stock';
$featured=$pr->is_featured() ? 'active' : '';
$video=get_post_meta($p->ID,'_product_video',true);
$video=$video ? '▶' : '—';

echo "<tr data-id='{$p->ID}'>
<td><input class='pfm-check' value='{$p->ID}' type='checkbox'></td>
<td class='pfm-serial'>{$i}</td>
<td class='pfm-img'>".get_the_post_thumbnail($p->ID,[40,40])."</td>
<td class='pfm-name'>
<strong>{$p->post_title}</strong>
<div class='row-actions'>
ID: {$p->ID} |
<a href='".get_edit_post_link($p->ID)."'>Edit</a> |
<a href='".get_delete_post_link($p->ID)."'>Trash</a> |
<a href='".get_permalink($p->ID)."' target='_blank'>View</a>
</div>
</td>
<td>{$price}</td>
<td>{$stock}</td>
<td>".wc_get_product_category_list($p->ID)."</td>
<td><span class='pfm-star {$featured}' data-id='{$p->ID}'>★</span></td>
<td>{$video}</td>
<td>".wc_get_product_tag_list($p->ID)."</td>
<td>".get_the_date('', $p->ID)."</td>
</tr>";
$i++;
}
wp_die();
});

add_action('wp_ajax_pfm_sort',function(){
foreach($_POST['order'] as $i=>$id){
wp_update_post(['ID'=>$id,'menu_order'=>$i]);
}
wp_die();
});

add_action('wp_ajax_pfm_bulk',function(){
if($_POST['bulk']=='trash'){
foreach($_POST['ids'] as $id){
wp_trash_post($id);
}}
wp_die();
});

add_action('wp_ajax_pfm_toggle_featured',function(){
$product=wc_get_product($_POST['id']);
$product->set_featured(!$product->is_featured());
$product->save();
wp_die();
});
