# Regras operacionais CI/CD

1. `main` representa produção.
2. Não desenvolver directamente em `main`.
3. Cada tarefa usa uma branch própria.
4. Todo o código chega à `main` através de Pull Request.
5. Pull Requests devem passar a validação Laravel e frontend.
6. Um merge para `main` é uma intenção de deploy para produção.
7. Secrets de produção não são guardados no repositório.
8. A base de dados local deve estar separada da base de produção.
9. O deploy manual fica reservado para contingência e diagnóstico durante a fase 1.
10. Alterações ao pipeline de deploy devem ser separadas de alterações funcionais sempre que possível.
