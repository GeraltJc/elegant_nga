<template>
  <div class="app">
    <header class="app-header">
      <div class="app-title">NGA 艾泽拉斯议事厅</div>
      <nav class="app-nav">
        <RouterLink to="/">主题列表</RouterLink>
        <RouterLink to="/crawl-runs">运行报表</RouterLink>
      </nav>
    </header>
    <main class="app-main">
      <RouterView />
    </main>
  </div>
</template>
