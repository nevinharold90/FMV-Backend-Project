<?php

namespace App\Http\Controllers\API\Product;

use App\Http\Controllers\API\BaseController;

use App\Models\Product;
use App\Models\ProductRestockOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ProductController extends BaseController
{

    // create a newly Product in storage.
    public function create(Request $request)
    {
        DB::beginTransaction();

        try {
            // Validate the input
            $validator = Validator::make($request->all(), [
                'category_id' => 'required|exists:categories,id',
                'original_price' => 'required|numeric',
                'product_name' => [
                    'required',
                    'string',
                    'max:255',
                    function ($attribute, $value, $fail) {
                        if (Product::where('product_name', $value)->exists()) {
                            $fail('The product name already exists.');
                        }
                    },
                ],
                'quantity' => 'required|integer|min:1', // Validate initial stock quantity
                'user_id' => 'required|exists:users,id', // Validate user_id
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 400);
            }

            // Create the product (add the initial quantity)
            $product = Product::create($request->only(['category_id', 'product_name', 'original_price', 'quantity']));

            // Log the initial quantity as a restock entry
            ProductRestockOrder::create([
                'product_id' => $product->id,
                'quantity' => $request->quantity,
                'user_id' => $request->user_id, // Include user_id in the restock entry
            ]);

            DB::commit();

            // Return response
            $response = [
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'category_name' => $product->category->category_name,
                'total_stock' => $product->quantity, // Total stock in products table
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }




    // Display the specified Product.
    public function show($id)
    {
        $Product = Product::find($id);

        if (is_null($Product)) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json($Product);
    }

    public function update(Request $request, $id)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|exists:categories,id',  // Category is optional
            'original_price' => 'nullable|numeric',  // Price is optional and numeric
            'product_name' => [
                'nullable',
                'string',
                'max:255',
                // Add a custom rule to check for unique product name excluding the current product
                function ($attribute, $value, $fail) use ($id) {
                    if (Product::where('product_name', $value)->where('id', '<>', $id)->exists()) {
                        $fail('The product name already exists.');
                    }
                },
            ],
            'quantity' => 'nullable|integer|min:1',  // Quantity is optional but must be greater than zero
        ]);

        // Handle validation errors
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Find the product or fail
            $product = Product::findOrFail($id);

            // Prepare an array to hold the updated data
            $updateData = [];

            // Check each field to see if it should be updated
            if ($request->has('category_id') && $request->category_id != $product->category_id) {
                $updateData['category_id'] = $request->category_id;
            }
            if ($request->has('original_price') && $request->original_price != $product->original_price) {
                $updateData['original_price'] = $request->original_price;
            }
            if ($request->has('product_name') && $request->product_name != $product->product_name) {
                $updateData['product_name'] = $request->product_name;
            }
            if ($request->has('quantity') && $request->quantity != $product->quantity) {
                $updateData['quantity'] = $request->quantity;
            }

            // Only update the product if any of the fields changed
            if (!empty($updateData)) {
                $product->update($updateData);
            }

            // Return a success response
            return response()->json(['success' => true, 'data' => $product], 200);

        } catch (ModelNotFoundException $e) {
            // Handle the case where the product is not found
            return response()->json(['message' => 'Product not found'], 404);
        } catch (\Exception $e) {
            // Handle other unexpected errors
            return response()->json(['message' => 'An error occurred while updating the product'], 500);
        }
    }




    // Remove the specified Product from storage.
    public function destroy($id)
    {
        $Product = Product::find($id);

        if (is_null($Product)) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $Product->delete();
        return response()->json(null, 204);
    }
}
