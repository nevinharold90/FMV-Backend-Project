<?php

namespace App\Http\Controllers\API\Product;

use App\Http\Controllers\API\BaseController;
use App\Http\Controllers\Controller;
use App\Models\ProductRestockOrder;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ProductRestockController extends BaseController
{
    // Display a listing of the Product restock orders.
    public function index()
    {
        $ProductRestockOrders = ProductRestockOrder::with('user', 'product')->get();
        return response()->json($ProductRestockOrders);
    }

    public function restockOrderByProductId($productID)
    {
        // Retrieve the product details
        $product = Product::with('category')->find($productID);

        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        // Retrieve restock orders for the product
        $ProductRestockOrders = ProductRestockOrder::with('user')
            ->where('product_id', $productID)
            ->get();

        // Calculate the total restock accumulated
        $totalRestockedQuantity = $ProductRestockOrders->sum('quantity');

        // Return the response with in stock and total restock accumulated
        return response()->json([
            'product_id' => $productID,
            'product_name' => $product->product_name,
            'category_name' => $product->category->category_name ?? null,
            'in_stock' => $product->quantity, // Current stock
            'total_restocked_quantity' => $totalRestockedQuantity, // Total restocked quantity
            'restock_orders' => $ProductRestockOrders // Detailed restock orders
        ], 200);
    }




    public function reorderLevel(Request $request)
    {
        $leadTime = 14; // Default lead time in days
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 20);

        // Fetch all products with category relation
        $products = Product::with('category')
            ->orderBy('quantity', 'asc')
            ->get();

        // Calculate reorder details
        $results = $products->map(function ($product) use ($leadTime) {
            $successfulDeliveries = DB::table('delivery_products')
                ->join('deliveries', 'delivery_products.delivery_id', '=', 'deliveries.id')
                ->where('delivery_products.product_id', $product->id)
                ->whereIn('deliveries.status', ['OD', 'P', 'S'])
                ->where('deliveries.created_at', '>=', now()->subDays(30))
                ->sum('delivery_products.quantity');

            $averageDailyUsage = $successfulDeliveries / 30;
            $safetyStock = $product->category->safety_stock ?? 70;
            $reorderLevel = ($averageDailyUsage * $leadTime) + $safetyStock;

            return [
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'current_quantity' => $product->quantity,
                'category_name' => $product->category->category_name ?? 'N/A', // Include category name
                'reorder_level' => round($reorderLevel, 2),
                'needs_reorder' => $product->quantity <= $reorderLevel,
            ];
        });

        // Filter products needing reorder
        $filteredResults = $results->filter(fn($product) => $product['needs_reorder']);

        // Get total count of products that need reorder
        $totalReorderCount = $filteredResults->count();

        // Paginate results
        $paginatedResults = $filteredResults->forPage($page, $limit)->values();

        // Return response with total count and paginated data
        return response()->json([
            'data' => $paginatedResults,
            'pagination' => [
                'total' => $totalReorderCount,
                'per_page' => $limit,
                'current_page' => $page,
                'last_page' => ceil($totalReorderCount / $limit),
            ],
            'reorder_count' => $totalReorderCount, // Total count of products needing reorder
        ]);
    }





// Temporary commented;
    // public function reorderLevel()
    // {
    //     // Define lead time
    //     $leadTime = 14; // Default lead time in days

    //     // Fetch all products with their category
    //     $products = Product::with('category')->get();

    //     // Calculate reorder details
    //     $results = $products->map(function ($product) use ($leadTime) {
    //         // Fetch total successful deliveries (use the last 30 days by default)
    //         $successfulDeliveries = DB::table('delivery_products')
    //             ->join('deliveries', 'delivery_products.delivery_id', '=', 'deliveries.id')
    //             ->where('delivery_products.product_id', $product->id)
    //             ->whereIn('deliveries.status', ['OD', 'P', 'S']) // Only successful statuses
    //             ->where('deliveries.created_at', '>=', now()->subDays(30)) // Last 30 days
    //             ->sum('delivery_products.quantity');

    //         // Calculate average daily usage
    //         $averageDailyUsage = $successfulDeliveries / 30;

    //         // Determine safety stock (from category or default to 70)
    //         $safetyStock = $product->category->safety_stock ?? 70;

    //         // Calculate reorder level
    //         $reorderLevel = ($averageDailyUsage * $leadTime) + $safetyStock;

    //         return [
    //             'product_id' => $product->id,
    //             'delivered_products' => $successfulDeliveries,
    //             'safe_stock' => $safetyStock,
    //             'product_name' => $product->product_name,
    //             'current_quantity' => $product->quantity,
    //             'category_name' => $product->category->category_name ?? 'Uncategorized',
    //             'average_daily_usage' => round($averageDailyUsage, 2),
    //             'reorder_level' => round($reorderLevel, 2),
    //             'needs_reorder' => $product->quantity <= $reorderLevel,
    //         ];
    //     });

    //     // Filter to include only products that need reorder
    //     $filteredResults = $results->filter(function ($product) {
    //         return $product['needs_reorder'] === true;
    //     });

    //     return response()->json([
    //         'data' => $filteredResults->values(), // Reset array keys
    //     ]);
    // }




    // Store a newly created restock order in storage.
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        DB::beginTransaction();
        try {
            // Create the product restock order
            $productRestockOrder = ProductRestockOrder::create($request->all());

            // Update the product quantity
            $product = Product::find($request->input('product_id'));
            $product->quantity += $request->input('quantity');
            $product->save();

            DB::commit();

            // Custom response
            $response = [
                'productRestock_id' => $productRestockOrder->id,
                'user' => [
                    'name' => $productRestockOrder->user->name,
                ],
                'product' => [
                    'name' => $product->product_name,
                    'restock_quantity' => $productRestockOrder->quantity,
                    'total quantity of product' => $product->quantity,
                ],
            ];

            return response()->json($response, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    // Display the specified product restock order.
    public function show($id)
    {
        // Retrieve the specific Product Restock Order with relationships
        $ProductRestockOrder = ProductRestockOrder::with('user', 'product')->find($id);

        if (is_null($ProductRestockOrder)) {
            return response()->json(['message' => 'Product Restock Order not found'], 404);
        }

        // Calculate the total restocked quantity for the product
        $totalRestockedQuantity = ProductRestockOrder::where('product_id', $ProductRestockOrder->product_id)->sum('quantity');

        // Get the total stock for the product
        $totalStock = $ProductRestockOrder->product->quantity;

        // Prepare a custom response
        $response = [
            'restock_id' => $ProductRestockOrder->id,
            'product_id' => $ProductRestockOrder->product_id,
            'product_name' => $ProductRestockOrder->product->product_name,
            'restock_quantity' => $ProductRestockOrder->quantity,
            'total_restocked_quantity' => $totalRestockedQuantity,
            'total_stock' => $totalStock,
            'user' => $ProductRestockOrder->user, // Include the user who handled the restock
        ];

        return response()->json($response);
    }

    // Update the specified restock order in storage.
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $ProductRestockOrder = ProductRestockOrder::find($id);

        if (is_null($ProductRestockOrder)) {
            return response()->json(['message' => 'Product Restock Order not found'], 404);
        }

        DB::beginTransaction();
        try {
            // Adjust the stock quantity before updating the restock order
            $product = Product::find($ProductRestockOrder->product_id);
            $product->quantity -= $ProductRestockOrder->quantity; // Subtract the old quantity

            // Update the restock order
            $ProductRestockOrder->update($request->all());

            // Add the new quantity
            $product->quantity += $request->input('quantity');
            $product->save();

            DB::commit();
            return response()->json($ProductRestockOrder->load('user', 'product'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error occurred while updating the product restock order'], 500);
        }
    }

    // Remove the specified restock order from storage.
    public function destroy($id)
    {
        $ProductRestockOrder = ProductRestockOrder::find($id);

        if (is_null($ProductRestockOrder)) {
            return response()->json(['message' => 'Restock Order not found'], 404);
        }

        DB::beginTransaction();
        try {
            // Adjust the stock quantity
            $product = Product::find($ProductRestockOrder->product_id);
            $product->quantity -= $ProductRestockOrder->quantity;
            $product->save();

            // Delete the restock order
            $ProductRestockOrder->delete();

            DB::commit();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error occurred while deleting the restock order'], 500);
        }
    }


    public function productTransactions($product_id, Request $request)
    {
        // Validate product ID
        $product = Product::find($product_id);
        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        // Get transaction type from the request
        $transactionType = $request->input('transactionType', 'all'); // Default to 'all' if not specified

        // Time period filter
        $timePeriod = $request->input('timePeriod', 'all');
        $dateLimit = null;

        switch ($timePeriod) {
            case '30_days':
                $dateLimit = now()->subDays(30);
                break;
            case '60_days':
                $dateLimit = now()->subDays(60);
                break;
            case '90_days':
                $dateLimit = now()->subDays(90);
                break;
            default:
                $dateLimit = null;
                break;
        }

        $transactions = collect();
        $totalRestockedQuantity = 0; // Initialize restock quantity sum

        // Fetch Restock Transactions if applicable
        if ($transactionType === 'all' || $transactionType === 'Restock') {
            $restocks = DB::table('product_restock_orders')
                ->join('products', 'product_restock_orders.product_id', '=', 'products.id')
                ->select(
                    DB::raw('NULL as delivery_id'),
                    'product_restock_orders.quantity',
                    DB::raw('FORMAT(product_restock_orders.quantity * products.original_price, 2) as total_value'),
                    'product_restock_orders.created_at as date',
                    DB::raw('"Restock" as transaction_type'),
                    DB::raw('NULL as delivery_status'),
                    DB::raw('NULL as total_damages')
                )
                ->where('product_restock_orders.product_id', $product_id);

            if ($dateLimit) {
                $restocks->where('product_restock_orders.created_at', '>=', $dateLimit);
            }

            // Calculate total restocked quantity
            $restockResults = $restocks->get();
            $totalRestockedQuantity = $restockResults->sum('quantity');

            $transactions = $transactions->merge($restockResults);
        }

        // Fetch Delivery Transactions if applicable
        if ($transactionType === 'all' || $transactionType === 'Delivery') {
            $deliveries = DB::table('delivery_products as dp')
                ->join('deliveries as d', 'dp.delivery_id', '=', 'd.id')
                ->join('product_details as pd', 'd.purchase_order_id', '=', 'pd.purchase_order_id')
                ->select(
                    'dp.delivery_id',
                    'dp.product_id',
                    'dp.quantity',
                    'dp.no_of_damages',
                    'pd.price',
                    DB::raw('(dp.quantity * pd.price) AS total_value'),
                    'd.created_at as date',
                    DB::raw('"Delivery" as transaction_type'),
                    'd.status as delivery_status'
                )
                ->where('dp.product_id', $product_id)
                ->where('d.status', 'S')
                ->distinct();

            if ($dateLimit) {
                $deliveries->where('d.created_at', '>=', $dateLimit);
            }

            $transactions = $transactions->merge($deliveries->get());
        }

        // Fetch Walk-In Transactions if applicable
        if ($transactionType === 'all' || $transactionType === 'Walk-In') {
            $walkIns = DB::table('purchase_orders as po')
                ->join('product_details as pd', 'po.id', '=', 'pd.purchase_order_id')
                ->select(
                    DB::raw('NULL as delivery_id'),
                    'pd.product_id',
                    'pd.quantity',
                    'pd.price',
                    DB::raw('(pd.quantity * pd.price) AS total_value'),
                    'po.created_at as date',
                    DB::raw('"Walk-In" as transaction_type'),
                    DB::raw('NULL as delivery_status'),
                    DB::raw('NULL as total_damages')
                )
                ->where('pd.product_id', $product_id)
                ->where('po.sale_type_id', '=', 2); // Assuming '2' corresponds to 'Walk-In'

            if ($dateLimit) {
                $walkIns->where('po.created_at', '>=', $dateLimit);
            }

            $transactions = $transactions->merge($walkIns->get());
        }

        // Sort transactions by date
        $sortedTransactions = $transactions->sortByDesc('date')->values();

        // Pagination Setup
        $perPage = $request->input('perPage', 10);
        $currentPage = $request->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;

        $paginatedTransactions = $sortedTransactions->slice($offset, $perPage)->values();

        // Return response
        return response()->json([
            'product_name' => $product->product_name,
            'product_created_date' => $product->created_at->format('m/d/Y'),
            'remaining_quantity' => $product->quantity,
            'total_restocked_quantity' => $totalRestockedQuantity, // Added total restock quantity here
            'product_id' => $product->id,
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
