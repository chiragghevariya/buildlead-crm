<script setup>
import EmptyState from '@/components/ui/EmptyState.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-canvas p-6">
    <div class="card w-full max-w-md">
      <EmptyState illustration="search" title="This page doesn't exist" description="The link may be outdated, or the page moved.">
        <BaseButton :to="{ name: 'dashboard' }">Back to dashboard</BaseButton>
      </EmptyState>
    </div>
  </div>
</template>
