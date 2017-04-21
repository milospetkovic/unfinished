<?php
declare(strict_types = 1);
namespace Core\Service\Article;

use Core\Mapper\ArticleMapper;
use Core\Mapper\ArticleTagsMapper;
use Core\Mapper\ArticlePostsMapper;
use Core\Mapper\TagsMapper;
use Core\Entity\ArticleType;
use Core\Filter\ArticleFilter;
use Core\Exception\FilterException;
use Core\Filter\PostFilter;
use Ramsey\Uuid\Uuid;
use MysqlUuid\Uuid as MysqlUuid;
use MysqlUuid\Formats\Binary;
use UploadHelper\Upload;
use Zend\Paginator\Paginator;

class PostService extends ArticleService
{
    /**
     * @var ArticleMapper
     */
    private $articleMapper;

    /**
     * @var ArticlePostsMapper
     */
    private $articlePostsMapper;

    /**
     * @var ArticleFilter
     */
    private $articleFilter;

    /**
     * @var PostFilter
     */
    private $postFilter;

    /**
     * @var ArticleTagsMapper
     */
    private $articleTagsMapper;

    /**
     * @var TagsMapper
     */
    private $tagsMapper;

    /**
     * @var Upload
     */
    private $upload;

    /**
     * PostService constructor.
     *
     * @param ArticleMapper $articleMapper
     * @param ArticlePostsMapper $articlePostsMapper
     * @param ArticleFilter $articleFilter
     * @param PostFilter $postFilter
     * @param ArticleTagsMapper $articleTagsMapper
     * @param TagsMapper $tagsMapper
     * @param Upload $upload
     */
    public function __construct(ArticleMapper $articleMapper, ArticlePostsMapper $articlePostsMapper, ArticleFilter $articleFilter,
                                PostFilter $postFilter, ArticleTagsMapper $articleTagsMapper, TagsMapper $tagsMapper, Upload $upload)
    {
        parent::__construct($articleMapper, $articleFilter);

        $this->articleMapper      = $articleMapper;
        $this->articlePostsMapper = $articlePostsMapper;
        $this->articleFilter      = $articleFilter;
        $this->postFilter         = $postFilter;
        $this->articleTagsMapper  = $articleTagsMapper;
        $this->tagsMapper         = $tagsMapper;
        $this->upload             = $upload;
    }

    public function fetchAllArticles($page, $limit) : Paginator
    {
        $select = $this->articlePostsMapper->getPaginationSelect();

        return $this->getPagination($select, $page, $limit);
    }

    public function fetchSingleArticleBySlug($slug)
    {
        $article = $this->articlePostsMapper->getBySlug($slug);

        if($article){
            $article['tags'] = [];
            foreach($this->articleMapper->getTages($article['article_uuid']) as $tag){
                $article['tags'][] = $tag->tag_id;
            }
        }

        return $article;
    }

    public function fetchSingleArticle($articleId)
    {
        $article = $this->articlePostsMapper->get($articleId);

        if($article){
            $article['tags'] = [];
            foreach($this->articleMapper->getTages($articleId) as $tag){
                $article['tags'][] = $tag->tag_id;
            }
        }

        return $article;
    }

    public function createArticle($user, $data)
    {
        $articleFilter = $this->articleFilter->getInputFilter()->setData($data);
        $postFilter    = $this->postFilter->getInputFilter()->setData($data);

        if(!$articleFilter->isValid() || !$postFilter->isValid()){
            throw new FilterException($articleFilter->getMessages() + $postFilter->getMessages());
        }

        $id   = Uuid::uuid1()->toString();
        $uuId = (new MysqlUuid($id))->toFormat(new Binary);

        $article = $articleFilter->getValues() + [
                'admin_user_uuid' => $user->admin_user_uuid,
                'type'            => ArticleType::POST,
                'article_id'      => $id,
                'article_uuid'    => $uuId
            ];

        $post = $postFilter->getValues() + [
                'featured_img' => $this->upload->uploadImage($data, 'featured_img'),
                'main_img'     => $this->upload->uploadImage($data, 'main_img'),
                'article_uuid' => $article['article_uuid']
            ];

        $this->articleMapper->insert($article);
        $this->articlePostsMapper->insert($post);

        if(isset($data['tags']) && $data['tags']){
            $tags = $this->tagsMapper->select(['tag_id' => $data['tags']]);
            $this->articleMapper->insertTags($tags, $article['article_uuid']);
        }
    }

    public function updateArticle($data, $id)
    {
        $article       = $this->articlePostsMapper->get($id);
        $articleFilter = $this->articleFilter->getInputFilter()->setData($data);
        $postFilter    = $this->postFilter->getInputFilter()->setData($data);

        if(!$articleFilter->isValid() || !$postFilter->isValid()){
            throw new FilterException($articleFilter->getMessages() + $postFilter->getMessages());
        }

        $article = $articleFilter->getValues() + ['article_uuid' => $article->article_uuid];
        $post    = $postFilter->getValues() + [
                'featured_img' => $this->upload->uploadImage($data, 'featured_img'),
                'main_img'     => $this->upload->uploadImage($data, 'main_img')
            ];

        // We dont want to force user to re-upload image on edit
        if(!$post['featured_img']){
            unset($post['featured_img']);
        }

        if(!$post['main_img']){
            unset($post['main_img']);
        }

        $this->articleMapper->update($article, ['article_uuid' => $article['article_uuid']]);
        $this->articlePostsMapper->update($post, ['article_uuid' => $article['article_uuid']]);
        $this->articleTagsMapper->delete(['article_uuid' => $article['article_uuid']]);

        if(isset($data['tags']) && $data['tags']){
            $tags = $this->tagsMapper->select(['tag_id' => $data['tags']]);
            $this->articleMapper->insertTags($tags, $article['article_uuid']);
        }
    }

    public function deleteArticle($id)
    {
        $post = $this->articlePostsMapper->get($id);

        if(!$post){
            throw new \Exception('Article not found!');
        }

        $this->articlePostsMapper->delete(['article_uuid' => $post->article_uuid]);
        $this->delete($post->article_uuid);
    }

}