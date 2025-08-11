<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Http\Request;

class UserDelegationRepository
{
    /**
     * Get the effective user for the request based on delegation logic.
     * 
     * If the authenticated user is a master user and delegate_user is provided,
     * return the delegate user. Otherwise, return the authenticated user.
     *
     * @param Request $request
     * @return User
     */
    public function getEffectiveUser(Request $request): User
    {
        $authenticatedUser = $request->user();
        
        // If no delegate_user parameter or user is not master, return authenticated user
        if (!$request->has('delegate_user') || !$authenticatedUser->master_user) {
            return $authenticatedUser;
        }
        
        // Get delegate user by nickname
        $delegateNickname = $request->input('delegate_user');
        $delegateUser = User::where('nickname', $delegateNickname)->first();
        
        // If delegate user exists, return it. Otherwise, return authenticated user as fallback
        return $delegateUser ?? $authenticatedUser;
    }
    
    /**
     * Check if delegation is active for the current request.
     *
     * @param Request $request
     * @return bool
     */
    public function isDelegationActive(Request $request): bool
    {
        $authenticatedUser = $request->user();
        
        return $authenticatedUser->master_user && 
               $request->has('delegate_user') && 
               $request->filled('delegate_user');
    }
    
    /**
     * Get the delegate user if delegation is active.
     *
     * @param Request $request
     * @return User|null
     */
    public function getDelegateUser(Request $request): ?User
    {
        if (!$this->isDelegationActive($request)) {
            return null;
        }
        
        $delegateNickname = $request->input('delegate_user');
        return User::where('nickname', $delegateNickname)->first();
    }
}