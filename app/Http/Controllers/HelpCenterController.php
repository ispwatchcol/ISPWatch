<?php

namespace App\Http\Controllers;

use App\Models\HelpArticle;
use App\Models\HelpCategory;
use Illuminate\Http\Request;

class HelpCenterController extends Controller
{
    public function index(Request $request)
    {
        $isSuperadmin = $request->user()?->is_superadmin;

        $categories = HelpCategory::with([
            $isSuperadmin ? 'articles' : 'publishedArticles',
        ])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(function ($cat) use ($isSuperadmin) {
                return [
                    'id'            => $cat->id,
                    'name'          => $cat->name,
                    'icon'          => $cat->icon,
                    'description'   => $cat->description,
                    'display_order' => $cat->display_order,
                    'articles'      => $isSuperadmin
                        ? $cat->articles->values()
                        : $cat->publishedArticles->values(),
                ];
            });

        return response()->json($categories);
    }

    public function storeCategory(Request $request)
    {
        $this->requireSuperadmin($request);

        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'icon'          => 'nullable|string|max:100',
            'description'   => 'nullable|string',
            'display_order' => 'nullable|integer',
        ]);

        $category = HelpCategory::create($validated);
        return response()->json($category, 201);
    }

    public function updateCategory(Request $request, int $id)
    {
        $this->requireSuperadmin($request);

        $category = HelpCategory::findOrFail($id);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'icon'          => 'nullable|string|max:100',
            'description'   => 'nullable|string',
            'display_order' => 'nullable|integer',
        ]);

        $category->update($validated);
        return response()->json($category);
    }

    public function destroyCategory(Request $request, int $id)
    {
        $this->requireSuperadmin($request);
        HelpCategory::findOrFail($id)->delete();
        return response()->json(['message' => 'Categoría eliminada']);
    }

    public function storeArticle(Request $request)
    {
        $this->requireSuperadmin($request);

        $validated = $request->validate([
            'category_id'   => 'required|exists:help_categories,id',
            'title'         => 'required|string|max:255',
            'content'       => 'required|string',
            'tips'          => 'nullable|string',
            'is_published'  => 'boolean',
            'display_order' => 'nullable|integer',
        ]);

        $article = HelpArticle::create($validated);
        return response()->json($article, 201);
    }

    public function updateArticle(Request $request, int $id)
    {
        $this->requireSuperadmin($request);

        $article = HelpArticle::findOrFail($id);

        $validated = $request->validate([
            'category_id'   => 'sometimes|exists:help_categories,id',
            'title'         => 'sometimes|string|max:255',
            'content'       => 'sometimes|string',
            'tips'          => 'nullable|string',
            'is_published'  => 'boolean',
            'display_order' => 'nullable|integer',
        ]);

        $article->update($validated);
        return response()->json($article);
    }

    public function destroyArticle(Request $request, int $id)
    {
        $this->requireSuperadmin($request);
        HelpArticle::findOrFail($id)->delete();
        return response()->json(['message' => 'Artículo eliminado']);
    }

    private function requireSuperadmin(Request $request): void
    {
        if (!$request->user()?->is_superadmin) {
            abort(403, 'Solo el superadministrador puede gestionar el centro de ayuda.');
        }
    }
}
