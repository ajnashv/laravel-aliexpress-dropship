<?php

namespace Webkul\Dropship\Repositories;

use Illuminate\Container\Container as App;
use Illuminate\Support\Facades\Event;
use Webkul\Core\Eloquent\Repository;

class AliExpressOrderRepository extends Repository
{
    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Dropship\Repositories\AliExpressProductRepository  $aliExpressProductRepository
     * @param  \Webkul\Dropship\Repositories\AliExpressOrderItemRepository  $aliExpressOrderItemRepository
     * @param  \Illuminate\Container\Container  $app
     * @return void
     */
    public function __construct(
        protected AliExpressProductRepository $aliExpressProductRepository,
        protected AliExpressOrderItemRepository $aliExpressOrderItemRepository,
        App $app
    )
    {
        parent::__construct($app);
    }

    /**
     * Specify Model class name
     *
     * @return mixed
     */
    function model()
    {
        return 'Webkul\Dropship\Contracts\AliExpressOrder';
    }

    /**
     * @param array $data
     * @return mixed
     */
    public function create(array $data)
    {
        $order = $data['order'];

        Event::dispatch('dropship.sales.order.save.before', $data);

        $aliExpressOrder = null;

        $queryParams = [
                'wk_product_qty' => [],
                'sku_attributes' => [],
                'wk_product_ids' => [],
            ];

        $productUrl = "";

        foreach ($order->items()->get() as $item) {
            $aliExpressProduct = $this->aliExpressProductRepository->findOneByField('product_id', $item->product->id);

            if (! $aliExpressProduct)
                continue;

            $productUrl = $aliExpressProduct->ali_express_product_url;

            $aliExpressOrder = $this->findOneByField('order_id', $order->id);

            if (! $aliExpressOrder) {
                $aliExpressOrder = parent::create([
                    'order_id' => $order->id
                ]);
            }

            $aliExpressOrderItem = $this->aliExpressOrderItemRepository->create([
                'ali_express_product_id' => $aliExpressProduct->id,
                'ali_express_order_id'   => $aliExpressOrder->id,
                'order_item_id'          => $item->id,
            ]);

            if ($childItem = $item->child) {
                $aliExpressChildProduct = $this->aliExpressProductRepository->findOneByField('product_id', $childItem->product->id);

                if ($aliExpressChildProduct) {
                    $aliExpressChildOrderItem = $this->aliExpressOrderItemRepository->create([
                        'ali_express_product_id' => $aliExpressChildProduct->id,
                        'ali_express_order_id'   => $aliExpressOrder->id,
                        'order_item_id'          => $childItem->id,
                        'parent_id'              => $aliExpressOrderItem->id
                    ]);

                    $queryParams['wk_product_ids'][] = $aliExpressProduct->ali_express_product_id;
                    $queryParams['wk_product_qty'][] = $item->qty_ordered;
                    $queryParams['sku_attributes'][] = str_replace('_', '+', $aliExpressChildProduct->combination_id);
                }
            } else {
                $queryParams['wk_product_ids'][] = $aliExpressProduct->ali_express_product_id;
                $queryParams['wk_product_qty'][] = $item->qty_ordered;
                $queryParams['sku_attributes'][] = 0;
            }
        }

        if ($aliExpressOrder) {
            $queryString = 'wk_order_id=' . $order->id
                        . '&wk_product_qty=' . implode('_', $queryParams['wk_product_qty'])
                        . '&sku_attributes=' . implode('_', $queryParams['sku_attributes'])
                        . '&wk_product_ids=' . implode('_', $queryParams['wk_product_ids']);

            $aliExpressOrder->ali_express_add_cart_url = $productUrl . (parse_url($productUrl, PHP_URL_QUERY) ? '&' : '?') . $queryString;

            $aliExpressOrder->save();

            Event::dispatch('dropship.sales.order.save.after', $aliExpressOrder);
        }
    }
}