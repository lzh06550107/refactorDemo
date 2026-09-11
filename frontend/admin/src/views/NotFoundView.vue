<template>
  <main class="not-found-view">
    <h1>404</h1>
    <p>页面不存在</p>
  </main>
</template>

<style scoped>
.not-found-view {
  min-height: 100vh;
  display: grid;
  place-content: center;
  gap: 0.75rem;
  padding: 2rem;
  text-align: center;
}

.not-found-view h1,
.not-found-view p {
  margin: 0;
}
</style>
