# Theme 架构

```text
Theme -> ThemeVersion
          ├-> StylePreset
StylePreset -> StyleInstance -> publish -> StyleSnapshot
ThemeVersion + StyleSnapshot -> SiteThemeRelease
Site -> activeThemeReleaseId
```

Theme 是非服务器任意执行型展示包。Module 通过 ViewContract/ViewModel 提供数据，ThemeRenderer 负责安全渲染。
