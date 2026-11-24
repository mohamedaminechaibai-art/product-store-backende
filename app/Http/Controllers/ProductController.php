<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();

        if ($request->filled('category')) {
            $category = mb_strtolower(trim((string) $request->input('category')));
            $query->whereRaw('LOWER(category) = ?', [$category]);
        }

        if ($request->filled('min_price') && is_numeric($request->input('min_price'))) {
            $query->where('price', '>=', (float) $request->input('min_price'));
        }

        if ($request->filled('max_price') && is_numeric($request->input('max_price'))) {
            $query->where('price', '<=', (float) $request->input('max_price'));
        }

        $search = trim((string) $request->input('search', ''));
        $perPage = max(1, min((int) $request->input('per_page', 6), 50));

        if ($search === '') {
            return response()->json($query->orderBy('created_at', 'desc')->paginate($perPage));
        }

        $searchLower = mb_strtolower($search);
        $words = preg_split('/\s+/u', $searchLower, -1, PREG_SPLIT_NO_EMPTY);

        $candidates = (clone $query)->where(function ($q) use ($searchLower, $words) {
            $q->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
              ->orWhereRaw('LOWER(description) LIKE ?', ["%{$searchLower}%"]);

            foreach ($words as $word) {
                $q->orWhereRaw('LOWER(title) LIKE ?', ["%{$word}%"])
                  ->orWhereRaw('LOWER(description) LIKE ?', ["%{$word}%"]);

                if (mb_strlen($word) >= 3) {
                    $prefix = mb_substr($word, 0, 3);
                    $q->orWhereRaw('LOWER(title) LIKE ?', ["%{$prefix}%"]);
                }
            }
        })->get();

        if ($candidates->isEmpty()) {
            $candidates = $query->orderBy('created_at', 'desc')->get();
        }

        $filteredItems = $candidates
            ->map(function ($product) use ($searchLower, $words) {
                $titleLower = mb_strtolower($product->title ?? '');
                $descLower = mb_strtolower($product->description ?? '');
                similar_text($searchLower, $titleLower, $percent);

                $wordHits = 0;
                foreach ($words as $word) {
                    if (str_contains($titleLower, $word) || str_contains($descLower, $word)) {
                        $wordHits++;
                    } else {
                        foreach (preg_split('/\s+/u', $titleLower, -1, PREG_SPLIT_NO_EMPTY) as $titleWord) {
                            if (max(mb_strlen($word), mb_strlen($titleWord)) > 0) {
                                $dist = levenshtein(
                                    iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $word) ?: $word,
                                    iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titleWord) ?: $titleWord
                                );
                                $allowedErrors = mb_strlen($word) <= 4 ? 1 : 2;
                                if ($dist <= $allowedErrors) {
                                    $wordHits += 0.8;
                                    break;
                                }
                            }
                        }
                    }
                }

                return [
                    'product' => $product,
                    'score' => $percent + ($wordHits * 25),
                    'wordHits' => $wordHits,
                ];
            })
            ->filter(fn ($item) => $item['wordHits'] > 0 || $item['score'] >= 45)
            ->sortByDesc('score')
            ->pluck('product')
            ->values();

        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $total = $filteredItems->count();
        $itemsForPage = $filteredItems->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return response()->json(new LengthAwarePaginator(
            $itemsForPage,
            $total,
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'image' => 'nullable|string|max:2048',
            'image_file' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:4096',
        ]);

        if ($request->hasFile('image_file')) {
            $path = $request->file('image_file')->store('products', 'public');
            $validated['image'] = 'storage/' . $path;
        }

        unset($validated['image_file']);
        $product = Product::create($validated);

        return response()->json($product, 201);
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'image' => 'nullable|string|max:2048',
            'image_file' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:4096',
        ]);

        if ($request->hasFile('image_file')) {
            if ($product->image && str_starts_with($product->image, 'storage/')) {
                Storage::disk('public')->delete(str_replace('storage/', '', $product->image));
            }
            $path = $request->file('image_file')->store('products', 'public');
            $validated['image'] = 'storage/' . $path;
        } elseif ($request->boolean('remove_image')) {
            if ($product->image && str_starts_with($product->image, 'storage/')) {
                Storage::disk('public')->delete(str_replace('storage/', '', $product->image));
            }
            $validated['image'] = null;
        }

        unset($validated['image_file']);
        $product->update($validated);

        return response()->json($product);
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        if ($product->image && str_starts_with($product->image, 'storage/')) {
            Storage::disk('public')->delete(str_replace('storage/', '', $product->image));
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted']);
    }

    public function show($id)
    {
        return response()->json(Product::findOrFail($id));
    }
}