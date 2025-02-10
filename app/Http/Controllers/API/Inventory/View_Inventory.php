<?php

namespace App\Http\Controllers\API\Inventory;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\API\BaseController;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class View_Inventory extends BaseController
{
    public function ViewTransaction(Request $request)
    {
        $transactionTypes = $request->input('transaction_types', ['all']);
        $categories = $request->input('categories', []);
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $searchTerm = $request->input('search', ''); // Get the search term
        $transactions = collect();
        $totalRestockedQuantity = 0;

        // Fetch Restock Transactions
        if (in_array('all', $transactionTypes) || in_array('Restock', $transactionTypes)) {
            $restocks = DB::table('product_restock_orders')
                ->join('products', 'product_restock_orders.product_id', '=', 'products.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->select(
                    'product_restock_orders.product_id',
                    'products.product_name',
                    'categories.category_name',
                    DB::raw('NULL as delivery_id'),
                    'product_restock_orders.quantity',
                    DB::raw('FORMAT(product_restock_orders.quantity * products.original_price, 2) as total_value'),
                    'product_restock_orders.created_at as date_in',
                    DB::raw('"Restock" as transaction_type'),
                    DB::raw('NULL as delivery_status'),
                    DB::raw('NULL as total_damages')
                );

            if ($dateFrom) $restocks->whereDate('product_restock_orders.created_at', '>=', $dateFrom);
            if ($dateTo) $restocks->whereDate('product_restock_orders.created_at', '<=', $dateTo);
            if (!empty($categories)) $restocks->whereIn('categories.category_name', $categories);
            if ($searchTerm) {
                $restocks->where(function ($query) use ($searchTerm) {
                    $query->where('products.product_name', 'like', "%{$searchTerm}%")
                          ->orWhere('categories.category_name', 'like', "%{$searchTerm}%");
                });
            }

            $restockResults = $restocks->get();
            $totalRestockedQuantity = $restockResults->sum('quantity');
            $transactions = $transactions->merge($restockResults);
        }

        // Fetch Delivery Transactions
        if (in_array('all', $transactionTypes) || in_array('Delivery', $transactionTypes)) {
            $deliveries = DB::table('delivery_products as dp')
                ->join('deliveries as d', 'dp.delivery_id', '=', 'd.id')
                ->join('product_details as pd', 'd.purchase_order_id', '=', 'pd.purchase_order_id')
                ->join('products', 'dp.product_id', '=', 'products.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->select(
                    'dp.product_id',
                    'products.product_name',
                    'categories.category_name',
                    'dp.delivery_id',
                    'dp.quantity',
                    'dp.no_of_damages',
                    'pd.price',
                    DB::raw('FORMAT(dp.quantity * pd.price, 2) AS total_value'),
                    'd.created_at as date_in',
                    'd.delivered_at as date_out',
                    DB::raw('"Delivery" as transaction_type'),
                    'd.status as delivery_status'
                )
                ->where('d.status', 'S')
                ->distinct();

            if ($dateFrom) $deliveries->whereDate('d.created_at', '>=', $dateFrom);
            if ($dateTo) $deliveries->whereDate('d.created_at', '<=', $dateTo);
            if (!empty($categories)) $deliveries->whereIn('categories.category_name', $categories);
            if ($searchTerm) {
                $deliveries->where(function ($query) use ($searchTerm) {
                    $query->where('products.product_name', 'like', "%{$searchTerm}%")
                          ->orWhere('categories.category_name', 'like', "%{$searchTerm}%");
                });
            }

            $transactions = $transactions->merge($deliveries->get());
        }

        // Fetch Walk-In Transactions
        if (in_array('all', $transactionTypes) || in_array('Walk-In', $transactionTypes)) {
            $walkIns = DB::table('purchase_orders as po')
                ->join('product_details as pd', 'po.id', '=', 'pd.purchase_order_id')
                ->join('products', 'pd.product_id', '=', 'products.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->select(
                    'pd.product_id',
                    'products.product_name',
                    'categories.category_name',
                    DB::raw('NULL as delivery_id'),
                    'pd.quantity',
                    'pd.price',
                    DB::raw('FORMAT(pd.quantity * pd.price, 2) AS total_value'),
                    'po.created_at as date_in',
                    DB::raw('"Walk-In" as transaction_type'),
                    DB::raw('NULL as delivery_status'),
                    DB::raw('NULL as total_damages')
                )
                ->where('po.sale_type_id', '=', 2);

            if ($dateFrom) $walkIns->whereDate('po.created_at', '>=', $dateFrom);
            if ($dateTo) $walkIns->whereDate('po.created_at', '<=', $dateTo);
            if (!empty($categories)) $walkIns->whereIn('categories.category_name', $categories);
            if ($searchTerm) {
                $walkIns->where(function ($query) use ($searchTerm) {
                    $query->where('products.product_name', 'like', "%{$searchTerm}%")
                          ->orWhere('categories.category_name', 'like', "%{$searchTerm}%");
                });
            }

            $transactions = $transactions->merge($walkIns->get());
        }

        // Sort transactions by date
        $sortedTransactions = $transactions->sortByDesc('date_in')->values();

        // Format the date values before sending response
        $formattedTransactions = $sortedTransactions->map(function ($transaction) {
            $transaction->date_in = $transaction->date_in ? Carbon::parse($transaction->date_in)->format('n/j/Y (g:i a)') : null;
            $transaction->date_out = isset($transaction->date_out) ? Carbon::parse($transaction->date_out)->format('n/j/Y (g:i a)') : null;
            return $transaction;
        });

        // Pagination
        $perPage = $request->input('perPage', 20);
        $currentPage = $request->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;

        $paginatedTransactions = $formattedTransactions->slice($offset, $perPage)->values();

        // Return response
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
