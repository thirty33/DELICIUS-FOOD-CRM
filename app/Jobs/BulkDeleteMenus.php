<?php

namespace App\Jobs;

use App\Models\Menu;
use App\Models\CategoryMenu;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BulkDeleteMenus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Los IDs de los menús a eliminar
     *
     * @var array
     */
    protected $menuIds;

    /**
     * Create a new job instance.
     *
     * @param array $menuIds
     * @return void
     */
    public function __construct(array $menuIds)
    {
        $this->menuIds = $menuIds;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        Log::info('Iniciando eliminación en masa de menús', ['count' => count($this->menuIds), 'ids' => $this->menuIds]);
        
        try {
            // Procesamos los menús en lotes para evitar sobrecarga de memoria
            foreach (array_chunk($this->menuIds, 100) as $chunk) {
                // Verificar si los menús existen realmente
                $existingMenus = Menu::whereIn('id', $chunk)->get();
                Log::info('Menús encontrados en la base de datos', [
                    'expected_count' => count($chunk),
                    'actual_count' => $existingMenus->count(),
                    'existing_ids' => $existingMenus->pluck('id')->toArray()
                ]);

                // Primero, eliminamos las relaciones CategoryMenu asociadas a estos menús
                $categoryMenusToDelete = CategoryMenu::whereIn('menu_id', $chunk)->get();
                
                Log::info('CategoryMenu asociados encontrados', [
                    'count' => $categoryMenusToDelete->count(),
                    'category_menu_ids' => $categoryMenusToDelete->pluck('id')->toArray(),
                    'menu_ids_with_categories' => $categoryMenusToDelete->pluck('menu_id')->unique()->toArray()
                ]);
                
                // Eliminar productos asociados a cada CategoryMenu
                foreach ($categoryMenusToDelete as $categoryMenu) {
                    $categoryMenuId = $categoryMenu->id;
                    $menuId = $categoryMenu->menu_id;
                    $categoryId = $categoryMenu->category_id;
                    
                    Log::info("Procesando CategoryMenu", [
                        'category_menu_id' => $categoryMenuId,
                        'menu_id' => $menuId,
                        'category_id' => $categoryId
                    ]);
                    
                    // Obtenemos los productos asociados antes de eliminarlos
                    $productsCount = $categoryMenu->products()->count();
                    $productIds = $categoryMenu->products()->pluck('product_id')->toArray();
                    
                    Log::info("Productos asociados al CategoryMenu $categoryMenuId", [
                        'count' => $productsCount,
                        'product_ids' => $productIds
                    ]);
                    
                    try {
                        // Eliminamos las relaciones de productos
                        $categoryMenu->products()->detach();
                        Log::info("Productos desvinculados del CategoryMenu $categoryMenuId");
                        
                        // Verificar que se hayan eliminado las relaciones
                        $remainingProducts = \DB::table('category_menu_product')
                            ->where('category_menu_id', $categoryMenuId)
                            ->count();
                        
                        Log::info("Verificación post-detach", [
                            'category_menu_id' => $categoryMenuId,
                            'remaining_products' => $remainingProducts
                        ]);
                        
                        // Eliminar el CategoryMenu
                        $deleteResult = $categoryMenu->delete();
                        Log::info("Resultado de eliminar CategoryMenu $categoryMenuId: " . ($deleteResult ? 'Éxito' : 'Fallo'));
                        
                        // Verificar que se haya eliminado el CategoryMenu
                        $stillExists = CategoryMenu::find($categoryMenuId);
                        Log::info("Verificación post-delete CategoryMenu", [
                            'category_menu_id' => $categoryMenuId,
                            'still_exists' => !is_null($stillExists)
                        ]);
                        
                    } catch (\Exception $e) {
                        Log::error("Error al procesar CategoryMenu $categoryMenuId", [
                            'error' => $e->getMessage(),
                            'stack' => $e->getTraceAsString()
                        ]);
                    }
                }
                
                // Ahora eliminamos los menús
                $deleteMenuResult = Menu::whereIn('id', $chunk)->delete();
                Log::info('Resultado de eliminar menús', [
                    'attempted_count' => count($chunk), 
                    'actual_deleted' => $deleteMenuResult
                ]);
                
                // Verificar que los menús realmente se hayan eliminado
                $remainingMenus = Menu::whereIn('id', $chunk)->count();
                Log::info('Verificación post-delete de menús', [
                    'menu_ids' => $chunk,
                    'remaining_count' => $remainingMenus
                ]);
            }
            
            Log::info('Eliminación en masa de menús completada');
        } catch (\Exception $e) {
            Log::error('Error al eliminar menús en masa', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Relanzar la excepción para que Laravel sepa que el job falló
            throw $e;
        }
    }
}