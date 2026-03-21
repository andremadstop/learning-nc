<template>
  <div class="pbq-reference-gallery">
    <div v-if="images.length > 1" class="pbq-reference-tabs">
      <button
        v-for="image in images"
        :key="image.id || image.src"
        class="pbq-reference-tab"
        :class="{ 'pbq-reference-tab--active': activeImageKey === (image.id || image.src) }"
        @click="activeImageKey = image.id || image.src"
      >
        {{ image.title || image.id || 'Reference' }}
      </button>
    </div>

    <div v-if="activeImage" class="pbq-reference-frame">
      <img :src="activeImage.src" :alt="activeImage.alt || activeImage.title || 'PBQ reference'" class="pbq-reference-image" />
    </div>

    <p v-if="activeImage && activeImage.caption" class="pbq-reference-caption">{{ activeImage.caption }}</p>
  </div>
</template>

<script>
export default {
  name: 'PbqReferenceGallery',
  props: {
    images: { type: Array, default: () => [] },
  },
  data() {
    const first = this.images[0] || null
    return {
      activeImageKey: first ? (first.id || first.src) : null,
    }
  },
  computed: {
    activeImage() {
      return this.images.find(image => (image.id || image.src) === this.activeImageKey) || this.images[0] || null
    },
  },
}
</script>

<style scoped>
.pbq-reference-gallery {
  margin-bottom: 18px;
}

.pbq-reference-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

.pbq-reference-tab {
  border: 1px solid var(--color-border);
  background: var(--color-main-background);
  color: var(--color-main-text);
  border-radius: 999px;
  padding: 6px 12px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
}

.pbq-reference-tab--active {
  background: var(--color-primary-element);
  border-color: var(--color-primary-element);
  color: #fff;
}

.pbq-reference-frame {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  overflow: hidden;
  background: #f6f8fb;
}

.pbq-reference-image {
  display: block;
  width: 100%;
  height: auto;
}

.pbq-reference-caption {
  margin: 8px 2px 0;
  color: var(--color-text-maxcontrast);
  font-size: 13px;
}
</style>
