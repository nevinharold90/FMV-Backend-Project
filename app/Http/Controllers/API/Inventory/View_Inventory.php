<?php

namespace App\Http\Controllers\API\Inventory;

use Illuminate\Http\Request;
use App\Http\Controllers\API\BaseController;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class View_Inventory extends BaseController
{

    public function ViewTransactionByProductId(Request $request, $product_id)
    {
        $perPage = (int) $request->input('per_page', 20);
        $currentPage = (int) $request->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;

        $transactions = collect();

        // Fetch Restock Transactions
        $restocks = DB::table('product_restock_orders')
            ->join('products', 'product_restock_orders.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select(
                'product_restock_orders.product_id',
                'products.product_name',
                DB::raw('TRIM(categories.category_name) as category_name'),
                'product_restock_orders.quantity',
                DB::raw('FORMAT(product_restock_orders.quantity * products.original_price, 2) as total_value'),
                'product_restock_orders.created_at as date_in',
                'product_restock_orders.created_at as date_out',
                DB::raw('"Restock" as transaction_type')
            )
            ->where('product_restock_orders.product_id', $product_id)
            ->get();

        $transactions = $transactions->merge($restocks);

        // Fetch Delivery Transactions (Using last restock as `date_in`)
        $deliveries = DB::table('delivery_products as dp')
            ->join('deliveries as d', 'dp.delivery_id', '=', 'd.id')
            ->join('product_details as pd', 'd.purchase_order_id', '=', 'pd.purchase_order_id')
            ->join('products', 'dp.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select(
                'dp.product_id',
                'products.product_name',
                DB::raw('TRIM(categories.category_name) as category_name'),
                'dp.quantity',
                'pd.price',
                DB::raw('FORMAT(dp.quantity * pd.price, 2) AS total_value'),
                DB::raw('(SELECT MAX(pro2.created_at)
                          FROM product_restock_orders pro2
                          WHERE pro2.product_id = dp.product_id
                          AND pro2.created_at <= d.created_at
                         ) AS date_in'),
                'd.created_at as date_out',
                DB::raw('"Delivery" as transaction_type')
            )
            ->where('dp.product_id', $product_id)
            ->where('d.status', 'S')
            ->get();

        $transactions = $transactions->merge($deliveries);

        // Fetch Walk-In Transactions (Using last restock as `date_in`)
        $walkIns = DB::table('purchase_orders as po')
            ->join('product_details as pd', 'po.id', '=', 'pd.purchase_order_id')
            ->join('products', 'pd.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select(
                'pd.product_id',
                'products.product_name',
                DB::raw('TRIM(categories.category_name) as category_name'),
                'pd.quantity',
                DB::raw('FORMAT(pd.quantity * products.original_price, 2) AS total_value'),
                DB::raw('(SELECT MAX(pro2.created_at)
                          FROM product_restock_orders pro2
                          WHERE pro2.product_id = pd.product_id
                          AND pro2.created_at <= po.created_at
                         ) AS date_in'),
                'po.created_at as date_out',
                DB::raw('"Walk-In" as transaction_type')
            )
            ->where('pd.product_id', $product_id)
            ->where('po.sale_type_id', '=', 2)
            ->get();

        $transactions = $transactions->merge($walkIns);

        // Sort Transactions by Date
        $sortedTransactions = $transactions->sortByDesc('date_out')->values();

        // Paginate the results manually
        $total = $transactions->count();
        $paginatedTransactions = $sortedTransactions->slice($offset, $perPage)->values();

        return response()->json([
            'transactions' => [
                'data' => $paginatedTransactions,
                'pagination' => [
                    'total' => $total,
                    'perPage' => $perPage,
                    'currentPage' => $currentPage,
                    'lastPage' => ceil($total / $perPage),
                ],
            ],
        ]);
    }


    public function ViewTransaction(Request $request)
    {
        $transactionTypes = $request->input('transaction_types', ['all']);
        $selectedCategories = $request->input('categories', []); // Dropdown categories
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $searchTerm = trim($request->input('search', '')); // Search input (trim whitespace)
        $searchType = $request->input('search_type', 'product'); // Search type (product/category)
        $transactions = collect();
        $totalRestockedQuantity = 0;

        // **Dropdown + Search Fix**
        // - If category dropdown is selected, filter strictly by it.
        // - If search term is entered, refine further.
        // - If no dropdown, use search only.
        $categoryFilter = !empty($selectedCategories) ? $selectedCategories : ($searchType === 'category' && $searchTerm ? [$searchTerm] : []);

        // **Fetch Restock Transactions**
        if (in_array('all', $transactionTypes) || in_array('Restock', $transactionTypes)) {
            $restocks = DB::table('product_restock_orders')
                ->join('products', 'product_restock_orders.product_id', '=', 'products.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->select(
                    'product_restock_orders.product_id',
                    'products.product_name',
                    DB::raw('TRIM(categories.category_name) as category_name'),
                    DB::raw('NULL as delivery_id'),
                    'product_restock_orders.quantity',
                    DB::raw('FORMAT(product_restock_orders.quantity * products.original_price, 2) as total_value'),
                    'product_restock_orders.created_at as date_in',
                    'product_restock_orders.created_at as date_out',
                    DB::raw('"Restock" as transaction_type'),
                    DB::raw('NULL as delivery_status'),
                    DB::raw('NULL as total_damages')
                );

            if ($dateFrom) $restocks->whereDate('product_restock_orders.created_at', '>=', $dateFrom);
            if ($dateTo) $restocks->whereDate('product_restock_orders.created_at', '<=', $dateTo);
            if (!empty($categoryFilter)) $restocks->whereIn(DB::raw('TRIM(categories.category_name)'), $categoryFilter);
            if ($searchTerm && $searchType === 'product') {
                $restocks->where('products.product_name', 'like', "%{$searchTerm}%");
            }

            $restockResults = $restocks->get();
            $totalRestockedQuantity = $restockResults->sum('quantity');
            $transactions = $transactions->merge($restockResults);
        }

        // **Fetch Delivery Transactions**
        if (in_array('all', $transactionTypes) || in_array('Delivery', $transactionTypes)) {
            $deliveries = DB::table('delivery_products as dp')
                ->join('deliveries as d', 'dp.delivery_id', '=', 'd.id')
                ->join('product_details as pd', 'd.purchase_order_id', '=', 'pd.purchase_order_id')
                ->join('products', 'dp.product_id', '=', 'products.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->select(
                    'dp.product_id',
                    'products.product_name',
                    DB::raw('TRIM(categories.category_name) as category_name'),
                    'dp.delivery_id',
                    'dp.quantity',
                    'dp.no_of_damages',
                    'pd.price',
                    DB::raw('FORMAT(dp.quantity * pd.price, 2) AS total_value'),
                    DB::raw('(SELECT MAX(pro2.created_at)
                              FROM product_restock_orders pro2
                              WHERE pro2.product_id = dp.product_id
                              AND pro2.created_at <= d.created_at
                             ) AS date_in'),
                    'd.created_at as date_out',
                    DB::raw('"Delivery" as transaction_type'),
                    'd.status as delivery_status'
                )
                ->where('d.status', 'S');

            if ($dateFrom) $deliveries->whereDate('d.created_at', '>=', $dateFrom);
            if ($dateTo) $deliveries->whereDate('d.created_at', '<=', $dateTo);
            if (!empty($categoryFilter)) $deliveries->whereIn(DB::raw('TRIM(categories.category_name)'), $categoryFilter);
            if ($searchTerm && $searchType === 'product') {
                $deliveries->where('products.product_name', 'like', "%{$searchTerm}%");
            }

            $transactions = $transactions->merge($deliveries->get());
        }

        // **Fetch Walk-In Transactions**
        if (in_array('all', $transactionTypes) || in_array('Walk-In', $transactionTypes)) {
            $walkIns = DB::table('purchase_orders as po')
                ->join('product_details as pd', 'po.id', '=', 'pd.purchase_order_id')
                ->join('products', 'pd.product_id', '=', 'products.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->select(
                    'po.id',
                    'po.customer_name',
                    'po.sale_type_id',
                    'products.product_name',
                    'pd.product_id',
                    'pd.quantity',
                    DB::raw('TRIM(categories.category_name) as category_name'),
                    DB::raw('(SELECT MAX(pro2.created_at)
                        FROM product_restock_orders pro2
                        WHERE pro2.product_id = pd.product_id
                        AND pro2.created_at <= po.created_at
                    ) AS date_in'),
                    'po.created_at as date_out',
                    DB::raw('FORMAT(pd.quantity * products.original_price, 2) AS total_value'),
                    DB::raw('"Walk-In" as transaction_type')
                )
                ->where('po.sale_type_id', '=', 2);

            if (!empty($categoryFilter)) {
                $walkIns->whereIn(DB::raw('TRIM(categories.category_name)'), $categoryFilter);
            } elseif ($searchTerm && $searchType === 'product') {
                $walkIns->where('products.product_name', 'like', "%{$searchTerm}%");
            }

            $transactions = $transactions->merge($walkIns->get());
        }

        // **Sorting Transactions**
        $sortedTransactions = $transactions->sortByDesc('date_out')->values();

        // **Format Dates**
        $formattedTransactions = $sortedTransactions->map(function ($transaction) {
            $transaction->date_in = $transaction->date_in ? Carbon::parse($transaction->date_in)->format('n/j/Y (g:i a)') : null;
            $transaction->date_out = isset($transaction->date_out) ? Carbon::parse($transaction->date_out)->format('n/j/Y (g:i a)') : null;
            return $transaction;
        });

        // **Pagination**
        $perPage = (int) $request->input('per_page', 20);
        $currentPage = (int) $request->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        $paginatedTransactions = $formattedTransactions->slice($offset, $perPage)->values();

        return response()->json([
            'total_restocked_quantity' => $totalRestockedQuantity,
            'transactions' => [
                'data' => $paginatedTransactions,
                'pagination' => [
                    'total' => $transactions->count(),
                    'perPage' => $perPage,
                    'currentPage' => $currentPage,
                    'lastPage' => ceil($transactions->count() / $perPage),
                ],
            ],
        ]);
    }
}
