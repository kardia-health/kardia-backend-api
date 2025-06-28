<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationDetailResource;
use App\Http\Resources\ConversationListResource;
use App\Models\Conversation;
use App\Repositories\ConversationRepository;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;


class ChatController extends Controller
{
    // Inject kedua class yang kita butuhkan
    public function __construct(
        private ChatService $chatService,
        private ConversationRepository $conversationRepository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $conversations = $this->conversationRepository->getUserConversations($request->user());
        $conversation = $conversations->first(); // Initialize $conversation

        $conversation->load('userProfile.user');

        if ($request->user()->id !== $conversation->userProfile->user_id) {
            return response()->json(['error' => 'Unauthorized access to conversation'], 403);
        }

        $conversations = $this->conversationRepository->getUserConversations($request->user());
        $conversations->load('chatMessages');
        return ConversationListResource::collection($conversations)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['message' => 'required|string|max:2000']);

        // Delegasikan pembuatan ke Repository
        $conversation = $this->conversationRepository->createConversation($request->user(), $validated['message']);

        // Panggil ChatService untuk memproses pesan pertama
        $aiReply = $this->chatService->getChatResponse($validated['message'], $request->user(), $conversation);

        $conversation->load('chatMessages');

        return response()->json([
            'conversation' => new ConversationListResource($conversation),
            'reply' => $aiReply
        ], 201);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse|ConversationDetailResource
    {
        $conversation->load('userProfile.user');

        if ($request->user()->id !== $conversation->userProfile->user_id) {
            return response()->json(['error' => 'Unauthorized access to conversation'], 403);
        }

        return new ConversationDetailResource($conversation->load('chatMessages'));
    }

    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->load('userProfile.user');

        if ($request->user()->id !== $conversation->userProfile->user_id) {
            return response()->json(['error' => 'Unauthorized access to conversation'], 403);
        }

        $validated = $request->validate(['message' => 'required|string|max:2000']);

        // Panggil ChatService, invalidasi akan ditangani di lapisan bawahnya
        $aiReply = $this->chatService->getChatResponse($validated['message'], $request->user(), $conversation);

        $conversation->load('chatMessages');
        return response()->json(['reply' => $aiReply]);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->load('userProfile.user');

        if ($request->user()->id !== $conversation->userProfile->user_id) {
            return response()->json(['error' => 'Unauthorized access to conversation'], 403);
        }

        $validated = $request->validate(['title' => 'required|string|max:100']);

        // Delegasikan update ke Repository
        $this->conversationRepository->updateTitle($conversation, $validated['title']);

        return response()->json($conversation->fresh());
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->load('userProfile.user');

        if ($request->user()->id !== $conversation->userProfile->user_id) {
            return response()->json(['error' => 'Unauthorized access to conversation'], 403);
        }
        // Delegasikan delete ke Repository
        $this->conversationRepository->deleteConversation($conversation);

        return response()->json(null, 204);
    }
}
