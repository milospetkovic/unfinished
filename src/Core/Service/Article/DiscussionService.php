<?php

namespace Core\Service\Article;

use Core\Mapper\ArticleMapper;
use Core\Mapper\ArticleDiscussionsMapper;
use Core\Mapper\ArticleTagsMapper;
use Core\Mapper\TagsMapper;
use Core\Entity\ArticleType;
use Core\Filter\ArticleFilter;
use Core\Filter\DiscussionFilter;
use Core\Exception\FilterException;
use Ramsey\Uuid\Uuid;
use MysqlUuid\Uuid as MysqlUuid;
use MysqlUuid\Formats\Binary;

class DiscussionService extends ArticleService
{
    private $articleMapper;
    private $articleDiscussionsMapper;
    private $articleFilter;
    private $discussionFilter;
    private $articleTagsMapper;
    private $tagsMapper;

    public function __construct(ArticleMapper $articleMapper, ArticleDiscussionsMapper $articleDiscussionsMapper, ArticleFilter $articleFilter,
                                DiscussionFilter $discussionFilter, ArticleTagsMapper $articleTagsMapper, TagsMapper $tagsMapper)
    {
        parent::__construct($articleMapper, $articleFilter);

        $this->articleMapper            = $articleMapper;
        $this->articleDiscussionsMapper = $articleDiscussionsMapper;
        $this->articleFilter            = $articleFilter;
        $this->discussionFilter         = $discussionFilter;
        $this->articleTagsMapper        = $articleTagsMapper;
        $this->tagsMapper               = $tagsMapper;
    }

    public function fetchAllArticles($page, $limit)
    {
        $select = $this->articleDiscussionsMapper->getPaginationSelect();

        return $this->getPagination($select, $page, $limit);
    }

    public function fetchSingleArticle($articleId)
    {
        $discussion = $this->articleDiscussionsMapper->get($articleId);

        if($discussion){
            $discussion['tags'] = $this->getTagIds($articleId);
        }

        return $discussion;
    }

    public function createArticle($user, $data)
    {
        $articleFilter    = $this->articleFilter->getInputFilter()->setData($data);
        $discussionFilter = $this->discussionFilter->getInputFilter()->setData($data);

        if(!$articleFilter->isValid() || !$discussionFilter->isValid()){
            throw new FilterException($articleFilter->getMessages() + $discussionFilter->getMessages());
        }

        $id   = Uuid::uuid1()->toString();
        $uuId = (new MysqlUuid($id))->toFormat(new Binary);

        $article = $articleFilter->getValues() + [
                'admin_user_uuid' => $user->admin_user_uuid,
                'type'            => ArticleType::DISCUSSION,
                'article_id'      => $id,
                'article_uuid'    => $uuId
            ];

        $discussion = $discussionFilter->getValues() + ['article_uuid' => $uuId];

        $this->articleMapper->insert($article);
        $this->articleDiscussionsMapper->insert($discussion);

        if(isset($data['tags'])){
            $tags = $this->tagsMapper->select(['tag_id' => $data['tags']]);
            $this->articleMapper->insertTags($tags, $article['article_uuid']);
        }
    }

    public function updateArticle($data, $id)
    {
        $article          = $this->articleDiscussionsMapper->get($id);
        $articleFilter    = $this->articleFilter->getInputFilter()->setData($data);
        $discussionFilter = $this->discussionFilter->getInputFilter()->setData($data);

        if(!$articleFilter->isValid() || !$discussionFilter->isValid()){
            throw new FilterException($articleFilter->getMessages() + $discussionFilter->getMessages());
        }

        $article    = $articleFilter->getValues() + ['article_uuid' => $article->article_uuid];
        $discussion = $discussionFilter->getValues();

        $this->articleMapper->update($article, ['article_uuid' => $article['article_uuid']]);
        $this->articleDiscussionsMapper->update($discussion, ['article_uuid' => $article['article_uuid']]);
        $this->articleTagsMapper->delete(['article_uuid' => $article['article_uuid']]);

        if(isset($data['tags'])){
            $tags = $this->tagsMapper->select(['tag_id' => $data['tags']]);
            $this->articleMapper->insertTags($tags, $article['article_uuid']);
        }
    }

    public function deleteArticle($id)
    {
        $discussion = $this->articleDiscussionsMapper->get($id);

        if(!$discussion){
            throw new \Exception('Article not found!');
        }

        $this->articleDiscussionsMapper->delete(['article_uuid' => $discussion->article_uuid]);
        $this->delete($discussion->article_uuid);
    }
}
