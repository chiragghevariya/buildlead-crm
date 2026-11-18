<script setup>
import Modal from '@/components/ui/Modal.vue'
import ShortcutsList from '@/components/ShortcutsList.vue'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
</script>

<template>
  <Modal v-model:open="ui.shortcutsOpen" title="Keyboard shortcuts" description="Work faster without the mouse." size="sm">
    <div class="-mx-6 -my-5"><ShortcutsList /></div>
  </Modal>
</template>
