<?php namespace EvolutionCMS\Controllers\Resources;

use EvolutionCMS\Models;
use EvolutionCMS\Controllers\AbstractResources;
use EvolutionCMS\Interfaces\ManagerTheme\TabControllerInterface;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent;

//'actions'=>array('edit'=>array(78,'edit_chunk'), 'duplicate'=>array(97,'new_chunk'), 'remove'=>array(80,'delete_chunk')),
class Chunks extends AbstractResources implements TabControllerInterface
{
    protected $view = 'page.resources.chunks';

    /**
     * @inheritdoc
     */
    public function getTabName(): string
    {
        return 'tabChunks';
    }

    /**
     * @inheritdoc
     */
    public function canView(): bool
    {
        return evolutionCMS()->hasAnyPermissions([
            'new_chunk',
            'edit_chunk'
        ]);
    }

    protected function getBaseParams()
    {
        return array_merge(
            parent::getParameters(),
            [
                'tabPageName' => $this->getTabName(),
                'tabName' => 'site_chunks'
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function getParameters(array $params = []) : array
    {
        $params = array_merge($this->getBaseParams(), $params);

        return $this->isNoData() ? $params : array_merge([
            'categories' => $this->parameterCategories(),
            'outCategory' => $this->parameterOutCategory()
        ], $params);
    }

    protected function parameterOutCategory() : Collection
    {
        return Models\SiteHtmlsnippet::where('category', '=', 0)
            ->orderBy('name', 'ASC')
            ->lockedView()
            ->get();
    }

    protected function parameterCategories() : Collection
    {
        return Models\Category::with('chunks')
            ->whereHas('chunks', function (Eloquent\Builder $builder) {
                return $builder->lockedView();
            })->orderBy('rank', 'ASC')
            ->get();
    }
}
